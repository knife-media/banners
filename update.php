<?php
/**
 * AdFox banners synchronization
 *
 * @author Anton Lukin
 * @version 1.0
 */

require_once __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

class Knife_Banners
{
    /**
     * Store static requests context
     */
    private static $context = null;

    /**
     * Spreadsheet id
     */
    private static $spreadsheet = null;

    /**
     * Get Google Sheets service
     */
    private static function get_service()
    {
        $credentials = dirname( __FILE__ ) . '/config/google-credentials.json';
        putenv( 'GOOGLE_APPLICATION_CREDENTIALS=' . $credentials );

        // Create google client
        $client = new Google_Client();
        $client->useApplicationDefaultCredentials();

        // Add only spreadsheets scope
        $client->addScope('https://www.googleapis.com/auth/spreadsheets');

        // Create new sheets service
        $service = new Google_Service_Sheets($client);

        return $service;
    }

    /**
     * Set queries context
     */
    private static function set_context($key)
    {
        $options = [
            "http" => [
                "method" => "GET",
                "header" => "X-Yandex-API-Key: {$key}"
            ]
        ];

        self::$context = stream_context_create($options);
    }

    /**
     * Wait for AdFox response
     */
    private static function get_task($task_id, $count = 20)
    {
        // Set request from task id
        $request = 'https://adfox.yandex.ru/api/report/result?taskId=' . $task_id;

        for ($i = 0; $i < $count; $i++) {
            // Sleep for 5 seconds
            sleep(5);

            // Send request
            $result = file_get_contents($request, false, self::$context);

            // Decode json output
            $output = json_decode($result);

            if (isset($output->result->state) && $output->result->state === 'SUCCESS') {
                break;
            }
        }

        return $output->result->table;
    }

    /**
     * Parse only required fields
     */
    private static function parse_fields($fields)
    {

    }

    /**
     * Get AdFox data
     */
    private static function parse_adfox($values)
    {
        $args = [
            'name' => 'places',
            'dateFrom' => '2020-06-15',
            'dateTo' => '2020-06-21',
            'sectionId' => $_ENV['SECTION']
        ];

        $request = 'https://adfox.yandex.ru/api/report/owner?' . http_build_query($args);

        // Send request
        $result = file_get_contents($request, false, self::$context);

        // Decode json output
        $output = json_decode($result);

        // Check if taskid exists
        if (empty($output->result->taskId)) {
            throw new Exception('AdFox empty task id');
        }

        // Wait for task completion
        $items = self::get_task($output->result->taskId);

        if (empty($items)) {
            throw new Exception('AdFox empty fields');
        }

        $fields = [];

        // Loop through spreadsheet values
        foreach ($values as $value) {
            // Find numbers by title
            foreach ($items as $item) {
                if ($item[3] === $value[0]) {
                    $fields[] = [ $item[10] ];
                }
            }
        }

        return $fields;
    }

    /**
     * Try to get spreadsheet items
     */
    public static function init()
    {
        self::$spreadsheet = $_ENV['SHEET_ID'];

        // Set requests context
        self::set_context($_ENV['YANDEX_KEY']);

        try {
            // Get Google sheets service
            $service = self::get_service();

            // Get spreadsheet column
            $column = $service->spreadsheets_values->get(self::$spreadsheet, 'B2:B11');

            // Get AdFox fields
            $fields = self::parse_adfox($column->values);

            $values = new Google_Service_Sheets_ValueRange( [
                'values' => $fields
            ] );

            $params = [
                'valueInputOption' => 'USER_ENTERED'
            ];

            $response = $service->spreadsheets_values->append(self::$spreadsheet, 'C2:C11', $values, $params);

        } catch (Exception $e) {
            $message = 'Error: ' . $e->getMessage();
            exit($message);
        }
    }
}

/**
 * Let's start
 */
Knife_Banners::init();