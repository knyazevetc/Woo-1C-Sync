<?php

declare(strict_types=1);

namespace Woo1cSync\Exchange\Actions;

/**
 * Builds and outputs CommerceML XML for orders not yet queried by 1C.
 */
final class QueryOrdersAction
{
    /**
     * Query unexported orders and render the CommerceML response.
     */
    public function execute(): void
    {
        if (!defined('WC1C_CURRENCY')) {
            define('WC1C_CURRENCY', null);
        }

        WC();
        $orderStatuses = array_keys(wc_get_order_statuses());
        $orderPosts = get_posts([
            'post_type' => 'shop_order',
            'post_status' => $orderStatuses,
            'meta_query' => [
                [
                    'key' => 'wc1c_queried',
                    'compare' => 'NOT EXISTS',
                ],
            ],
        ]);

        $orderPostIds = [];
        $documents = [];
        foreach ($orderPosts as $orderPost) {
            $order = wc_get_order($orderPost);
            if (!$order) {
                wc1c_error('Failed to get order');
            }

            $orderPostIds[] = $orderPost->ID;

            $orderLineItems = $order->get_items();

            foreach ($orderLineItems as $key => $orderLineItem) {
                $productId = $orderLineItem['variation_id']
                    ? $orderLineItem['variation_id']
                    : $orderLineItem['product_id'];
                $guid = get_post_meta($productId, '_wc1c_guid', true);

                $orderLineItems[$key]['wc1c_guid'] = $guid;
            }

            $orderShippingItems = $order->get_shipping_methods();

            $orderMeta = get_post_meta($orderPost->ID, null, true);
            foreach ($orderMeta as $metaKey => $metaValue) {
                $orderMeta[$metaKey] = $metaValue[0];
            }

            $addressItems = [
                'postcode' => 'Почтовый индекс',
                'country_name' => 'Страна',
                'state' => 'Регион',
                'city' => 'Город',
            ];
            $contactItems = [
                'email' => 'Почта',
                'phone' => 'ТелефонРабочий',
            ];

            $contragentMeta = get_post_meta($orderPost->ID, 'wc1c_contragent', true);
            $contragents = [];
            foreach (['billing', 'shipping'] as $type) {
                $contragent = [];

                $name = [];
                foreach (['last_name', 'first_name'] as $nameKey) {
                    $metaKey = "_{$type}_$nameKey";
                    if (empty($orderMeta[$metaKey])) {
                        continue;
                    }

                    $name[] = $orderMeta[$metaKey];
                    $contragent[$nameKey] = $orderMeta[$metaKey];
                }

                $name = implode(' ', $name);
                if (!$name) {
                    $contragent['name'] = $contragentMeta ? $contragentMeta : 'Гость';
                    $contragent['user_id'] = 0;
                } else {
                    $contragent['name'] = $name;
                    $contragent['user_id'] = $order->get_customer_id();
                }

                if (!empty($orderMeta["_{$type}_country"])) {
                    $countryCode = $orderMeta["_{$type}_country"];
                    $orderMeta["_{$type}_country_name"] = WC()->countries->countries[$countryCode];
                }

                $fullAddress = [];
                foreach (['postcode', 'country_name', 'state', 'city', 'address_1', 'address_2'] as $addressKey) {
                    $metaKey = "_{$type}_$addressKey";
                    if (!empty($orderMeta[$metaKey])) {
                        $fullAddress[] = $orderMeta[$metaKey];
                    }
                }
                $contragent['full_address'] = implode(', ', $fullAddress);

                $contragent['address'] = [];
                foreach ($addressItems as $addressKey => $addressItemName) {
                    if (empty($orderMeta["_{$type}_$addressKey"])) {
                        continue;
                    }

                    $contragent['address'][$addressItemName] = $orderMeta["_{$type}_$addressKey"];
                }

                $contragent['contacts'] = [];
                foreach ($contactItems as $contactKey => $contactItemName) {
                    if (empty($orderMeta["_{$type}_$contactKey"])) {
                        continue;
                    }

                    $contragent['contacts'][$contactItemName] = $orderMeta["_{$type}_$contactKey"];
                }

                $contragents[$type] = $contragent;
            }

            $products = [];
            foreach ($orderLineItems as $orderLineItem) {
                $products[] = [
                    'guid' => $orderLineItem['wc1c_guid'],
                    'name' => $orderLineItem['name'],
                    'price_per_item' => $orderLineItem['line_total'] / $orderLineItem['qty'],
                    'quantity' => $orderLineItem['qty'],
                    'total' => $orderLineItem['line_total'],
                    'type' => 'Товар',
                ];
            }

            foreach ($orderShippingItems as $orderShippingItem) {
                if (!$orderShippingItem['cost']) {
                    continue;
                }

                $products[] = [
                    'guid' => 'ORDER_DELIVERY',
                    'name' => $orderShippingItem['name'],
                    'price_per_item' => $orderShippingItem['cost'],
                    'quantity' => 1,
                    'total' => $orderShippingItem['cost'],
                    'type' => 'Услуга',
                ];
            }

            $statuses = [
                'cancelled' => 'Отменен',
                'trash' => 'Удален',
            ];
            $status = $order->get_status();
            if (array_key_exists($status, $statuses)) {
                $orderStatusName = $statuses[$status];
            } else {
                $orderStatusName = wc_get_order_status_name($status);
            }

            if (WC1C_CURRENCY) {
                $documentCurrency = WC1C_CURRENCY;
            } else {
                $documentCurrency = get_option('wc1c_currency', @$orderMeta['_order_currency']);
            }

            $document = [
                'order_id' => $orderPost->ID,
                'currency' => $documentCurrency,
                'total' => @$orderMeta['_order_total'],
                'comment' => $orderPost->post_excerpt,
                'contragents' => $contragents,
                'products' => $products,
                'payment_method_title' => @$orderMeta['_payment_method_title'],
                'status' => $status,
                'status_name' => $orderStatusName,
                'has_shipping' => count($orderShippingItems) > 0,
                'modified_at' => $orderPost->post_modified,
            ];
            [$document['date'], $document['time']] = explode(' ', $orderPost->post_date, 2);

            $documents[] = $document;
        }

        $documents = apply_filters('wc1c_query_documents', $documents);

        echo '<?xml version="1.0" encoding="' . WC1C_XML_CHARSET . '"?>';
        ?>
<КоммерческаяИнформация ВерсияСхемы="2.05" ДатаФормирования="<?php echo date('Y-m-dTH:i:s', WC1C_TIMESTAMP); ?>">
  <?php foreach ($documents as $document): ?>
    <Документ>
      <Ид>wc1c#order#<?php echo $document['order_id']; ?></Ид>
      <Номер><?php echo $document['order_id']; ?></Номер>
      <Дата><?php echo $document['date']; ?></Дата>
      <Время><?php echo $document['time']; ?></Время>
      <ХозОперация>Заказ товара</ХозОперация>
      <Роль>Продавец</Роль>
      <Валюта><?php echo $document['currency']; ?></Валюта>
      <Сумма><?php echo $document['total']; ?></Сумма>
      <Комментарий><?php echo $document['comment']; ?></Комментарий>
      <Контрагенты>
        <?php foreach ($document['contragents'] as $type => $contragent): ?>
          <Контрагент>
            <Ид>wc1c#user#<?php echo $contragent['user_id']; ?></Ид>
            <Роль><?php echo $type == 'billing' ? 'Плательщик' : 'Получатель'; ?></Роль>
            <?php if (!empty($contragent['name'])): ?>
              <Наименование><?php echo $contragent['name']; ?></Наименование>
              <ПолноеНаименование><?php echo $contragent['name']; ?></ПолноеНаименование>
            <?php endif; ?>
            <?php if (!empty($contragent['first_name'])): ?>
              <Имя><?php echo $contragent['first_name']; ?></Имя>
            <?php endif; ?>
            <?php if (!empty($contragent['last_name'])): ?>
              <Фамилия><?php echo $contragent['last_name']; ?></Фамилия>
            <?php endif; ?>
            <?php if (!empty($contragent['full_address']) || $contragent['address']): ?>
              <АдресРегистрации>
                <?php if (!empty($contragent['full_address'])): ?>
                  <Представление><?php echo $contragent['full_address']; ?></Представление>
                <?php endif; ?>
                <?php foreach ($contragent['address'] as $addressItemName => $addressItemValue): ?>
                  <АдресноеПоле>
                    <Тип><?php echo $addressItemName; ?></Тип>
                    <Значение><?php echo $addressItemValue; ?></Значение>
                  </АдресноеПоле>
                <?php endforeach; ?>
              </АдресРегистрации>
            <?php endif; ?>
            <Контакты>
              <?php foreach ($contragent['contacts'] as $contactItemName => $contactItemValue): ?>
                <Контакт>
                  <Тип><?php echo $contactItemName; ?></Тип>
                  <Значение><?php echo $contactItemValue; ?></Значение>
                </Контакт>
              <?php endforeach; ?>
            </Контакты>
          </Контрагент>
        <?php endforeach; ?>
      </Контрагенты>
      <Товары>
        <?php foreach ($document['products'] as $product): ?>
          <Товар>
            <?php if (!empty($product['guid'])): ?>
              <Ид><?php echo $product['guid']; ?></Ид>
            <?php endif; ?>
            <Наименование><?php echo $product['name']; ?></Наименование>
            <БазоваяЕдиница Код="796" НаименованиеПолное="Штука" МеждународноеСокращение="PCE">шт</БазоваяЕдиница>
            <ЦенаЗаЕдиницу><?php echo $product['price_per_item']; ?></ЦенаЗаЕдиницу>
            <Количество><?php echo $product['quantity']; ?></Количество>
            <Сумма><?php echo $product['total']; ?></Сумма>
            <ЗначенияРеквизитов>
              <ЗначениеРеквизита>
                <Наименование>ТипНоменклатуры</Наименование>
                <Значение><?php echo $product['type']; ?></Значение>
              </ЗначениеРеквизита>
            </ЗначенияРеквизитов>
          </Товар>
        <?php endforeach; ?>
      </Товары>
      <ЗначенияРеквизитов>
        <?php
        $requisites = [
            'Заказ оплачен' => !in_array($document['status'], ['on-hold', 'pending']) ? 'true' : 'false',
            'Доставка разрешена' => $document['has_shipping'] ? 'true' : 'false',
            'Отменен' => $document['status'] == 'cancelled' ? 'true' : 'false',
            'Финальный статус' => !in_array($document['status'], ['trash', 'on-hold', 'pending', 'processing']) ? 'true' : 'false',
            'Статус заказа' => $document['status_name'],
            'Дата изменения статуса' => $document['modified_at'],
        ];
        if ($document['payment_method_title']) {
            $requisites['Метод оплаты'] = $document['payment_method_title'];
        }
        $requisites = apply_filters('wc1c_query_order_requisites', $requisites, $document);
        foreach ($requisites as $requisiteKey => $requisiteValue): ?>
          <ЗначениеРеквизита>
            <Наименование><?php echo $requisiteKey; ?></Наименование>
            <Значение><?php echo $requisiteValue; ?></Значение>
          </ЗначениеРеквизита>
        <?php endforeach; ?>
      </ЗначенияРеквизитов>
    </Документ>
  <?php endforeach; ?>
</КоммерческаяИнформация>
        <?php

        foreach ($orderPostIds as $orderPostId) {
            update_post_meta($orderPostId, 'wc1c_querying', 1);
        }
    }
}
