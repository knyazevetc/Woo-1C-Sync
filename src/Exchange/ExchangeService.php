<?php

declare(strict_types=1);

namespace Woo1cSync\Exchange;

use Woo1cSync\Exchange\Actions\ConfirmOrdersAction;
use Woo1cSync\Exchange\Actions\QueryOrdersAction;
use Woo1cSync\Exchange\Handlers\ImportHandler;
use Woo1cSync\Exchange\Handlers\OffersHandler;
use Woo1cSync\Exchange\Handlers\OrdersHandler;
use Woo1cSync\Plugin;
use WP_Error;

/**
 * Core CommerceML exchange protocol: authentication, file transfer, XML import, and order export.
 */
final class ExchangeService
{
    private static ?self $instance = null;

    private bool $isError = false;

    private bool $isTransaction = false;

    /** @var array<int, string> */
    private array $xmlNames = [];

    private int $xmlDepth = -1;

    private ?bool $isFull = null;

    private ?bool $isMoysklad = null;

    private string $namespace = '';

    private ?ImportHandler $importHandler = null;

    private ?OffersHandler $offersHandler = null;

    private ?OrdersHandler $ordersHandler = null;

    /**
     * Return the singleton exchange service instance.
     */
    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Register WordPress hooks for exchange routing.
     */
    public function registerHooks(): void
    {
        add_filter('query_vars', [$this, 'queryVars']);
        add_action('template_redirect', [$this, 'templateRedirect'], -10);
    }

    /**
     * Add the wc1c query variable for rewrite rules.
     *
     * @param array<int, string> $queryVars
     *
     * @return array<int, string>
     */
    public function queryVars(array $queryVars): array
    {
        $queryVars[] = 'wc1c';

        return $queryVars;
    }

    /**
     * Route exchange and cleanup requests based on the wc1c query variable.
     */
    public function templateRedirect(): void
    {
        $value = get_query_var('wc1c');
        if (empty($value)) {
            return;
        }

        if (strpos($value, '?') !== false) {
            [$value, $query] = explode('?', $value, 2);
            parse_str($query, $query);
            $_GET = array_merge($_GET, $query);
        }
        $_GET['wc1c'] = $value;

        if ($value == 'exchange') {
            status_header(200);
            $this->exchange();
        } elseif ($value == 'clean') {
            Plugin::instance()->cleanup()->run();
            exit;
        }
    }

    /**
     * Main exchange entry point dispatched by 1C CommerceML protocol.
     */
    public function exchange(): void
    {
        @ini_set('display_errors', '0');
        @ini_set('html_errors', '0');

        $this->setStrictMode();
        $this->setOutputCallback();
        $this->fixFastcgiGet();

        if (empty($_GET['type'])) {
            $this->error('No type');
        }
        if (empty($_GET['mode'])) {
            $this->error('No mode');
        }

        if ($_GET['mode'] == 'checkauth') {
            $this->modeCheckauth();
        }

        $this->checkAuth();

        if (!defined('WC1C_DEBUG')) {
            define('WC1C_DEBUG', false);
        }

        if (!defined('WC1C_TIMESTAMP')) {
            define('WC1C_TIMESTAMP', time());
        }

        if ($_GET['mode'] == 'init') {
            $this->modeInit($_GET['type']);
        } elseif ($_GET['mode'] == 'file') {
            $this->modeFile($_GET['type'], $_GET['filename'] ?? '');
        } elseif ($_GET['mode'] == 'import') {
            $this->modeImport($_GET['type'], $_GET['filename']);
        } elseif ($_GET['mode'] == 'query') {
            $this->modeQuery($_GET['type']);
        } elseif ($_GET['mode'] == 'success') {
            $this->modeSuccess($_GET['type']);
        } else {
            $this->error('Unknown mode');
        }
    }

    /**
     * Whether debug mode is enabled for exchange responses.
     */
    public function isDebug(): bool
    {
        return (defined('WP_DEBUG') && WP_DEBUG) || (defined('WC1C_DEBUG') && WC1C_DEBUG);
    }

    /**
     * End an open database transaction.
     *
     * @param bool $isCommit Whether to commit instead of rolling back.
     * @param bool $noCheck Skip wpdb error check after the query.
     */
    public function wpdbEnd(bool $isCommit = false, bool $noCheck = false): void
    {
        global $wpdb;

        if (!$this->isTransaction) {
            return;
        }

        $this->isTransaction = false;

        $sqlQuery = !$isCommit ? 'ROLLBACK' : 'COMMIT';
        $wpdb->query($sqlQuery);
        if (!$noCheck) {
            $this->checkWpdbError();
        }

        if ($this->isDebug()) {
            echo "\n" . strtolower($sqlQuery);
        }
    }

    /**
     * Build the full request URI for debug output.
     */
    public function fullRequestUri(): string
    {
        $uri = 'http';
        if (@$_SERVER['HTTPS'] == 'on') {
            $uri .= 's';
        }
        $uri .= "://{$_SERVER['SERVER_NAME']}";
        if ($_SERVER['SERVER_PORT'] != 80) {
            $uri .= ":{$_SERVER['SERVER_PORT']}";
        }
        if (isset($_SERVER['REQUEST_URI'])) {
            $uri .= $_SERVER['REQUEST_URI'];
        }

        return $uri;
    }

    /**
     * Log and output an exchange error, then exit unless suppressed.
     *
     * @param bool $noExit When true, do not terminate the request.
     */
    public function error(string $message, string $type = 'Error', bool $noExit = false): void
    {
        $this->isError = true;

        $message = "$type: $message";
        $lastChar = substr($message, -1);
        if (!in_array($lastChar, ['.', '!', '?'], true)) {
            $message .= '.';
        }

        error_log($message);
        echo "$message\n";

        if ($this->isDebug()) {
            echo "\n";
            debug_print_backtrace();

            $info = [
                'Request URI' => $this->fullRequestUri(),
                'Server API' => PHP_SAPI,
                'Memory limit' => ini_get('memory_limit'),
                'Maximum POST size' => ini_get('post_max_size'),
                'PHP version' => PHP_VERSION,
                'WordPress version' => get_bloginfo('version'),
                'Plugin version' => defined('WC1C_VERSION') ? WC1C_VERSION : '',
            ];
            echo "\n";
            foreach ($info as $infoName => $infoValue) {
                echo "$infoName: $infoValue\n";
            }
        }

        if (!$noExit) {
            $this->wpdbEnd();
            exit;
        }
    }

    /**
     * Install strict error and exception handlers for exchange requests.
     */
    public function setStrictMode(): void
    {
        set_error_handler([$this, 'strictErrorHandler']);
        set_exception_handler([$this, 'strictExceptionHandler']);
    }

    /**
     * Output buffer callback that sets content type and encoding.
     *
     * @param string $buffer Buffered output.
     */
    public function outputCallback(string $buffer): string
    {
        if (!headers_sent()) {
            $isXml = isset($_GET['mode']) && $_GET['mode'] == 'query';
            $contentType = !$isXml || $this->isError ? 'text/plain' : 'text/xml';
            header('Content-Type: ' . $contentType . '; charset=' . WC1C_XML_CHARSET);
        }

        if (WC1C_XML_CHARSET == 'UTF-8') {
            $buffer = "\xEF\xBB\xBF$buffer";
        } else {
            $buffer = mb_convert_encoding($buffer, WC1C_XML_CHARSET, 'UTF-8');
        }

        return $buffer;
    }

    /**
     * Start output buffering with the exchange callback.
     */
    public function setOutputCallback(): void
    {
        ob_start([$this, 'outputCallback']);
    }

    /**
     * PHP error handler that promotes fatal errors to exchange errors.
     *
     * @param mixed $errno
     * @param mixed $errstr
     * @param mixed $errfile
     * @param mixed $errline
     * @param mixed $errcontext
     *
     * @return bool
     */
    public function strictErrorHandler($errno, $errstr, $errfile, $errline, $errcontext = null): bool
    {
        if (error_reporting() === 0) {
            return false;
        }

        switch ($errno) {
            case E_NOTICE:
            case E_USER_NOTICE:
            case E_WARNING:
            case E_USER_WARNING:
            case E_DEPRECATED:
            case E_USER_DEPRECATED:
                return false;
            case E_ERROR:
            case E_USER_ERROR:
                $type = 'Fatal Error';
                break;
            default:
                $type = 'Unknown Error';
        }
        if (!isset($type)) {
            return false;
        }

        $message = sprintf('%s in %s on line %d', $errstr, $errfile, $errline);
        $this->error($message, "PHP $type");

        return false;
    }

    /**
     * Uncaught exception handler for exchange requests.
     */
    public function strictExceptionHandler(\Throwable $exception): void
    {
        $message = sprintf(
            '%s in %s on line %d',
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
        );
        $this->error($message, 'Exception');
    }

    /**
     * Populate $_GET from the request URI when running under FastCGI.
     */
    public function fixFastcgiGet(): void
    {
        if (!$_GET && isset($_SERVER['REQUEST_URI'])) {
            $query = parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY);
            parse_str((string) $query, $_GET);
        }
    }

    /**
     * Return the parent XML element name at a given depth offset.
     *
     * @param array<int, string> $names
     */
    public function xmlParentName(array $names, int $depth, int $ancestor = 1): ?string
    {
        $index = $depth - $ancestor;
        if ($index < 0 || !isset($names[$index])) {
            return null;
        }

        return $names[$index];
    }

    /**
     * Delete all WordPress transients from the options table.
     */
    public function cleanupTransients(): void
    {
        global $wpdb;

        $wpdb->query("DELETE FROM $wpdb->options WHERE option_name LIKE '_transient_%'");
        $wpdb->last_error = '';
    }

    /**
     * Append character data to a flat XML accumulator array.
     *
     * @param array<string|int, mixed> $array
     * @param string|int $key
     */
    public function xmlAppend(array &$array, $key, string $data): void
    {
        if (!isset($array[$key])) {
            $array[$key] = '';
        }
        $array[$key] .= $data;
    }

    /**
     * Append character data to a nested XML accumulator array.
     *
     * @param array<int, array<string, mixed>> $array
     */
    public function xmlAppendNested(array &$array, int $index, string $key, string $data): void
    {
        if (!isset($array[$index])) {
            $array[$index] = [];
        }
        $this->xmlAppend($array[$index], $key, $data);
    }

    /**
     * Recursively delete all files in a directory.
     */
    public function cleanupDir(string $pathDir): void
    {
        if (!is_dir($pathDir)) {
            return;
        }
        $files = array_diff(scandir($pathDir), ['.', '..']);
        foreach ($files as $file) {
            $path = "$pathDir/$file";
            is_dir($path) ? $this->cleanupDir($path) : unlink($path);
        }
    }

    /**
     * Verify the user has shop manager or administrator capabilities.
     *
     * @param \WP_User $user
     */
    public function checkPermissions($user): void
    {
        if (!user_can($user, 'shop_manager') && !user_can($user, 'administrator')) {
            $this->error('No permissions');
        }
    }

    /**
     * Format and output a WordPress error object.
     *
     * @param WP_Error $wpError
     */
    public function wpError(WP_Error $wpError, ?string $onlyErrorCode = null): void
    {
        $messages = [];
        foreach ($wpError->get_error_codes() as $errorCode) {
            if ($onlyErrorCode && $errorCode != $onlyErrorCode) {
                continue;
            }

            $wpErrorMessages = implode(', ', $wpError->get_error_messages($errorCode));
            $wpErrorMessages = strip_tags($wpErrorMessages);
            $messages[] = sprintf('%s: %s', $errorCode, $wpErrorMessages);
        }

        $this->error(implode('; ', $messages), 'WP Error');
    }

    /**
     * Abort the exchange if the value is a WordPress error.
     *
     * @param mixed $wpError
     */
    public function checkWpError($wpError): void
    {
        if (is_wp_error($wpError)) {
            $this->wpError($wpError);
        }
    }

    /**
     * Handle CommerceML checkauth mode and return an auth cookie.
     */
    public function modeCheckauth(): void
    {
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $serverKey) {
            if (!isset($_SERVER[$serverKey]) || $_SERVER[$serverKey] === '') {
                continue;
            }

            $authHeaderParts = explode(' ', $_SERVER[$serverKey], 2);
            if (count($authHeaderParts) < 2) {
                continue;
            }
            $authValue = base64_decode($authHeaderParts[1]);
            $authParts = explode(':', $authValue, 2);
            if (count($authParts) < 2) {
                continue;
            }
            [$_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']] = $authParts;

            break;
        }

        if (!isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'])) {
            $this->error('No authentication credentials');
        }

        $user = wp_authenticate($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']);
        $this->checkWpError($user);
        $this->checkPermissions($user);

        $expiration = time() + apply_filters('auth_cookie_expiration', DAY_IN_SECONDS, $user->ID, false);
        $authCookie = wp_generate_auth_cookie($user->ID, $expiration);

        exit("success\nwc1c-auth\n$authCookie");
    }

    /**
     * Validate the exchange session cookie or current user.
     */
    public function checkAuth(): void
    {
        if (preg_match('/ Development Server$/', $_SERVER['SERVER_SOFTWARE'])) {
            return;
        }

        if (!empty($_COOKIE['wc1c-auth'])) {
            $user = wp_validate_auth_cookie($_COOKIE['wc1c-auth'], 'auth');
            if (!$user) {
                $this->error('Invalid cookie');
            }
        } else {
            $user = wp_get_current_user();
            if (!$user->ID) {
                $this->error('Not logged in');
            }
        }

        $this->checkPermissions($user);
    }

    /**
     * Convert a PHP ini size string to bytes.
     *
     * @param string|int $filesize
     */
    public function filesizeToBytes($filesize): int
    {
        $filesize = (string) $filesize;
        switch (substr($filesize, -1)) {
            case 'G':
            case 'g':
                return (int) $filesize * 1000000000;
            case 'M':
            case 'm':
                return (int) $filesize * 1000000;
            case 'K':
            case 'k':
                return (int) $filesize * 1000;
            default:
                return (int) $filesize;
        }
    }

    /**
     * Handle CommerceML init mode and return zip support and file limits.
     */
    public function modeInit(string $type): void
    {
        $dataDir = WC1C_DATA_DIR . $type;
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0755, true);
        }
        if (WC1C_CLEANUP_GARBAGE) {
            $this->cleanupDir($dataDir);
        }
        @exec('which unzip', $_, $status);
        $isZip = @$status === 0 || class_exists('ZipArchive');
        if (!$isZip) {
            $this->error('The PHP extension zip is required.');
        }

        $fileLimits = [
            $this->filesizeToBytes('10M'),
            $this->filesizeToBytes(ini_get('post_max_size')),
            $this->filesizeToBytes(ini_get('memory_limit')),
        ];
        @exec('grep ^MemFree: /proc/meminfo', $output, $status);
        if (@$status === 0 && $output) {
            $output = preg_split("/\s+/", $output[0]);
            $fileLimits[] = (int) ($output[1] * 1000 * 0.7);
        }
        if (defined('WC1C_FILE_LIMIT') && WC1C_FILE_LIMIT) {
            $fileLimits[] = $this->filesizeToBytes(WC1C_FILE_LIMIT);
        }
        $fileLimit = min($fileLimits);

        exit("zip=yes\nfile_limit=$fileLimit");
    }

    /**
     * Handle CommerceML file upload mode.
     */
    public function modeFile(string $type, string $filename): void
    {
        if ($filename) {
            $dataDir = WC1C_DATA_DIR . $type;
            if (!is_dir($dataDir)) {
                mkdir($dataDir, 0755, true);
            }

            $path = $dataDir . '/' . ltrim($filename, "./\\");

            $inputFile = fopen('php://input', 'r');
            if (!$inputFile) {
                $this->error(sprintf('Failed to read request body for file %s', $filename));
            }

            $tempPath = "$path~";
            $tempFile = fopen($tempPath, 'w');
            if (!$tempFile) {
                $this->error(sprintf('Failed to create temp file for %s', $filename));
            }

            stream_copy_to_stream($inputFile, $tempFile);
            fclose($inputFile);
            fclose($tempFile);

            if (is_file($path)) {
                $tempHeader = file_get_contents($tempPath, false, null, 0, 32);
                if (strpos($tempHeader, '<?xml ') !== false) {
                    unlink($path);
                }
            }

            $tempFile = fopen($tempPath, 'r');
            if (!$tempFile) {
                $this->error(sprintf('Failed to read temp file for %s', $filename));
            }

            $file = fopen($path, 'a');
            if (!$file) {
                $this->error(sprintf('Failed to open file %s for writing', $filename));
            }

            stream_copy_to_stream($tempFile, $file);
            fclose($tempFile);
            fclose($file);
            unlink($tempPath);

            if (!is_file($path) || filesize($path) === 0) {
                $this->error(sprintf('Failed to save file %s', $filename));
            }
        }

        if ($type == 'catalog') {
            exit('success');
        } elseif ($type == 'sale') {
            $this->unpackFiles($type);

            $dataDir = WC1C_DATA_DIR . $type;
            foreach (glob("$dataDir/*.xml") as $path) {
                $filename = basename($path);
                $this->modeImport($type, $filename, 'orders');
            }
        }
    }

    /**
     * Abort the exchange on the last database error.
     */
    public function checkWpdbError(): void
    {
        global $wpdb;

        if (!$wpdb->last_error) {
            return;
        }

        $this->error(
            sprintf('%s for query "%s"', $wpdb->last_error, $wpdb->last_query),
            'DB Error',
            true,
        );

        $this->wpdbEnd(false, true);
        exit;
    }

    /**
     * Raise PHP execution time limits for long-running imports.
     */
    public function disableTimeLimit(): void
    {
        @ini_set('max_execution_time', '3600');
        @ini_set('max_input_time', '3600');

        $disabledFunctions = array_map('trim', explode(',', ini_get('disable_functions')));
        if (!in_array('set_time_limit', $disabledFunctions, true)) {
            @set_time_limit(0);
        }
    }

    /**
     * Begin a database transaction for atomic import operations.
     */
    public function setTransactionMode(): void
    {
        global $wpdb;

        $this->disableTimeLimit();
        register_shutdown_function([$this, 'transactionShutdown']);

        $wpdb->show_errors(false);

        $this->isTransaction = true;
        $wpdb->query('START TRANSACTION');
        $this->checkWpdbError();
    }

    /**
     * Commit or roll back the transaction on shutdown depending on fatal errors.
     */
    public function transactionShutdown(): void
    {
        $error = error_get_last();
        $isCommit = true;
        if ($error) {
            $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
            if (in_array($error['type'], $fatalTypes, true)) {
                $isCommit = false;
            }
        }

        $this->wpdbEnd($isCommit);
    }

    /**
     * Extract uploaded zip archives in the exchange data directory.
     */
    public function unpackFiles(string $type): void
    {
        $dataDir = WC1C_DATA_DIR . $type;
        $zipPaths = glob("$dataDir/*.zip");
        if (!$zipPaths) {
            return;
        }
        ob_end_clean();

        $command = sprintf(
            'unzip -qqo -x %s -d %s',
            implode(' ', array_map('escapeshellarg', $zipPaths)),
            escapeshellarg($dataDir),
        );
        @exec($command, $_, $status);

        if (@$status !== 0) {
            foreach ($zipPaths as $zipPath) {
                $zip = new \ZipArchive();
                $result = $zip->open($zipPath);
                if ($result !== true) {
                    $this->error(sprintf('Failed open archive %s with error code %d', $zipPath, $result));
                }

                $zip->extractTo($dataDir) or $this->error(sprintf('Failed to extract from archive %s', $zipPath));
                $zip->close() or $this->error(sprintf('Failed to close archive %s', $zipPath));
            }
        }

        foreach ($zipPaths as $zipPath) {
            unlink($zipPath) or $this->error(sprintf('Failed to unlink file %s', $zipPath));
        }

        if ($type == 'catalog') {
            exit('progress');
        }
    }

    /**
     * XML parser start-element callback dispatched to the active handler.
     *
     * @param resource $parser
     * @param array<string, string> $attrs
     */
    public function xmlStartElementHandler($parser, string $name, array $attrs): void
    {
        $this->xmlNames[] = $name;
        $this->xmlDepth++;

        $handler = $this->getActiveHandler();
        $handler->startElementHandler(
            $this->isFull,
            $this->xmlNames,
            $this->xmlDepth,
            $name,
            $attrs,
        );

        static $elementNumber = 0;
        $elementNumber++;
        if ($elementNumber > 1000) {
            $elementNumber = 0;
            wp_cache_flush();
        }
    }

    /**
     * XML parser character-data callback dispatched to the active handler.
     *
     * @param resource $parser
     */
    public function xmlCharacterDataHandler($parser, string $data): void
    {
        $name = $this->xmlNames[$this->xmlDepth];
        $handler = $this->getActiveHandler();
        $handler->characterDataHandler(
            $this->isFull,
            $this->xmlNames,
            $this->xmlDepth,
            $name,
            $data,
        );
    }

    /**
     * XML parser end-element callback dispatched to the active handler.
     *
     * @param resource $parser
     */
    public function xmlEndElementHandler($parser, string $name): void
    {
        $handler = $this->getActiveHandler();
        $handler->endElementHandler(
            $this->isFull,
            $this->xmlNames,
            $this->xmlDepth,
            $name,
        );

        array_pop($this->xmlNames);
        $this->xmlDepth--;
    }

    /**
     * Parse a CommerceML XML file using the PHP xml extension.
     *
     * @param resource $fp
     */
    public function xmlParse($fp): void
    {
        $parser = xml_parser_create();

        xml_set_element_handler($parser, [$this, 'xmlStartElementHandler'], [$this, 'xmlEndElementHandler']);
        xml_set_character_data_handler($parser, [$this, 'xmlCharacterDataHandler']);

        $metaData = stream_get_meta_data($fp);
        $filename = basename($metaData['uri']);

        while (!($isFinal = feof($fp))) {
            if (($data = fread($fp, 4096)) === false) {
                $this->error(sprintf('Failed to read from file %s', $filename));
            }
            if (!xml_parse($parser, $data, $isFinal)) {
                $message = sprintf(
                    '%s in %s on line %d',
                    xml_error_string(xml_get_error_code($parser)),
                    $filename,
                    xml_get_current_line_number($parser),
                );
                $this->error($message, 'XML Error');
            }
        }

        xml_parser_free($parser);
    }

    /**
     * Read the XML header to detect full vs incremental and MoySklad format.
     *
     * @param resource $fp
     *
     * @return array{0: bool|null, 1: bool|null}
     */
    public function xmlParseHead($fp): array
    {
        $isFull = null;
        $isMoysklad = null;
        while (($buffer = fgets($fp)) !== false) {
            if (strpos($buffer, ' СинхронизацияТоваров=') !== false) {
                $isMoysklad = true;
            }

            if (
                strpos($buffer, ' СодержитТолькоИзменения=') === false
                && strpos($buffer, '<СодержитТолькоИзменения>') === false
            ) {
                continue;
            }

            $isFull = strpos($buffer, ' СодержитТолькоИзменения="false"') !== false
                || strpos($buffer, '<СодержитТолькоИзменения>false<') !== false;
            break;
        }

        $metaData = stream_get_meta_data($fp);
        $filename = basename($metaData['uri']);

        rewind($fp) or $this->error(sprintf('Failed to rewind on file %s', $filename));

        return [$isFull, $isMoysklad];
    }

    /**
     * Handle CommerceML import mode for catalog, offers, or orders XML files.
     */
    public function modeImport(string $type, string $filename, ?string $namespace = null): void
    {
        if ($type == 'catalog') {
            $this->unpackFiles($type);
        }

        $path = WC1C_DATA_DIR . "$type/$filename";
        $fp = fopen($path, 'r') or $this->error(sprintf('Failed to open file %s', $filename));
        flock($fp, LOCK_EX) or $this->error(sprintf('Failed to lock file %s', $filename));

        $this->setTransactionMode();

        if (!$namespace) {
            $namespace = preg_replace('/^([a-zA-Z]+).+/', '$1', $filename);
        }
        if (!in_array($namespace, ['import', 'offers', 'orders'], true)) {
            $this->error(sprintf('Unknown import file type: %s', $namespace));
        }

        $this->namespace = $namespace;
        [$this->isFull, $this->isMoysklad] = $this->xmlParseHead($fp);
        $GLOBALS['wc1c_is_moysklad'] = $this->isMoysklad;
        $this->xmlNames = [];
        $this->xmlDepth = -1;

        $this->bootstrapHandler($namespace);

        $this->xmlParse($fp);

        flock($fp, LOCK_UN) or $this->error(sprintf('Failed to unlock file %s', $filename));
        fclose($fp) or $this->error(sprintf('Failed to close file %s', $filename));

        exit('success');
    }

    /**
     * Look up a post ID by meta key and value with object cache.
     *
     * @param mixed $value
     *
     * @return int|string|null
     */
    public function postIdByMeta(string $key, $value)
    {
        global $wpdb;

        if ($value === null) {
            return null;
        }

        $cacheKey = "wc1c_post_id_by_meta-$key-$value";
        $postId = wp_cache_get($cacheKey);
        if ($postId === false) {
            $postId = $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM $wpdb->postmeta JOIN $wpdb->posts ON post_id = ID WHERE meta_key = %s AND meta_value = %s",
                $key,
                $value,
            ));
            $this->checkWpdbError();

            if ($postId) {
                wp_cache_set($cacheKey, $postId);
            }
        }

        return $postId;
    }

    /**
     * Handle CommerceML query mode and output order XML.
     */
    public function modeQuery(string $type): void
    {
        (new QueryOrdersAction())->execute();
        exit;
    }

    /**
     * Handle CommerceML success mode and mark orders as exported.
     */
    public function modeSuccess(string $type): void
    {
        (new ConfirmOrdersAction())->execute();
        exit('success');
    }

    /**
     * Load the handler bootstrap for the given CommerceML namespace.
     */
    private function bootstrapHandler(string $namespace): void
    {
        $bootstrap = WC1C_PLUGIN_DIR . "exchange/{$namespace}.php";
        if (!is_readable($bootstrap)) {
            $this->error(sprintf('Handler bootstrap not found: %s', $namespace));
        }

        require_once $bootstrap;
    }

    /**
     * Return the active XML handler for the current import namespace.
     *
     * @return ImportHandler|OffersHandler|OrdersHandler
     */
    private function getActiveHandler()
    {
        switch ($this->namespace) {
            case 'import':
                if ($this->importHandler === null) {
                    $this->importHandler = ExchangeSupport::getImportHandler();
                }
                if ($this->importHandler === null) {
                    $this->error('Import handler not initialized');
                }

                return $this->importHandler;

            case 'offers':
                if ($this->offersHandler === null) {
                    $this->offersHandler = ExchangeSupport::getOffersHandler();
                }
                if ($this->offersHandler === null) {
                    $this->error('Offers handler not initialized');
                }

                return $this->offersHandler;

            case 'orders':
                if ($this->ordersHandler === null) {
                    $this->ordersHandler = ExchangeSupport::getOrdersHandler();
                }
                if ($this->ordersHandler === null) {
                    $this->error('Orders handler not initialized');
                }

                return $this->ordersHandler;

            default:
                $this->error(sprintf('Unknown handler namespace: %s', $this->namespace));
        }
    }
}
