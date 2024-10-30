<?php
defined('ABSPATH') or die('Nice try...');

/**
 * SmartCache
 * (c) 2017. Code Dragon Software LLP
 *
 * Cron functions
 *
 * @package    Smart_Cache
 * @subpackage Smart_Cache/includes
 * @author     Dragon Slayer <info@codedragon.ca>
 */

class Smart_Cache_Cron extends Smart_Cache_Base {
    public $cron_queue_key;
    public $cron_hook;

    public function __construct($plugin_file_name){
        parent::__construct($plugin_file_name);
        $this->file = $plugin_file_name;
        $this->cron_hook = SMART_CACHE_PLUGIN_NAME . '-cron';
        $this->cron_queue_key = SMART_CACHE_PLUGIN_NAME . '-tasks';

        if(defined('WP_CRON_CUSTOM_HTTP_BASIC_USERNAME') && defined('WP_CRON_CUSTOM_HTTP_BASIC_PASSWORD')) {
            add_filter('cron_request', array($this, 'http_basic_cron_request'));
        }

        add_filter('cron_schedules', array($this, 'cron_schedules'));
        add_action($this->cron_hook, array($this, 'start_task'));
    }

    /**
     * Add the server username and password to the HTTP headers
     * @param  array $cron_request
     * @return array
     */
    public function http_basic_cron_request($cron_request) {
        $headers = array('Authorization' => sprintf('Basic %s', base64_encode(WP_CRON_CUSTOM_HTTP_BASIC_USERNAME . ':' . WP_CRON_CUSTOM_HTTP_BASIC_PASSWORD)));
        $cron_request['args']['headers'] = isset($cron_request['args']['headers']) ? array_merge($cron_request['args']['headers'], $headers) : $headers;
        return $cron_request;
    }

    /**
     * Establish custom cron schedules
     * @param  array $schedules
     * @return array
     */
    public function cron_schedules($schedules){
        $prefix = 'smart_cache_cron_'; // Avoid conflict with other crons. Example Reference: cron_30_mins
        $schedule_options = array(
            '1_min' => array(
                'display' => '1 Minute',
                'interval' => MINUTE_IN_SECONDS
            ),
            '30_mins' => array(
                'display' => '30 Minutes',
                'interval' => 30 * MINUTE_IN_SECONDS
            ),
            '1_hours' => array(
                'display' => 'Hour',
                'interval' => 60 * MINUTE_IN_SECONDS
            ),
            '2_hours' => array(
                'display' => '2 Hours',
                'interval' => 2 * 60 * MINUTE_IN_SECONDS
            )
        );

        /* Add each custom schedule into the cron job system. */
        foreach($schedule_options as $schedule_key => $schedule){
            $schedules[$prefix . $schedule_key] = array(
                'interval' => $schedule['interval'],
                'display' => __('Every ' . $schedule['display'])
            );
        }
        return $schedules;
    }

    /**
     * Schedule a WP cron task
     * @param  array $task      Task parameters (timestamp, recurrence)
     * @return boolean
     */
    public function schedule_task($task){
        // Must have task information.
        if(!$task){
            return false;
        }

        // Set list of required task keys.
        $required_keys = array(
            'timestamp',
            'recurrence'
        );

        // Verify the necessary task information exists.
        $missing_keys = array();
        foreach($required_keys as $key){
            if(!array_key_exists($key, $task)){
                $missing_keys[] = $key;
            }
        }

        // Check for missing keys.
        if(!empty($missing_keys)){
            echo 'Cron task keys missing: ' . join('<br/>', $missing_keys);
            return false;
        }

        // Task must not already be scheduled.
        if(wp_next_scheduled($this->cron_hook)){
            wp_clear_scheduled_hook($this->cron_hook);
        }

        // Schedule the task to run.
        if(empty($task['recurrence']))
            wp_schedule_single_event($task['timestamp'], $this->cron_hook);
        else
            wp_schedule_event($task['timestamp'], 'smart_cache_cron_' . $task['recurrence'], $this->cron_hook);
        return true;
    }

    /**
     * Add series of tasks (queue) to database
     * @param  array $queue
     */
    public function save_queue($queue){
        if(!empty($queue) && is_array($queue)){
            $tasks = get_site_option($this->cron_queue_key, null);
            if(empty($tasks)){
                update_site_option($this->cron_queue_key, $queue);
            }
        }
    }

    /**
     * Begin the cron
     */
    public function start_task() {
        $item = $this->get_queue_item();
        if(empty($item)){
            $this->clear_tasks();
        }else{
            $done = $this->do_task($item);
            if($done){
                $this->reduce_queue();
            }
        }
    }

    /**
     * Retrieve a queued task
     * @return array
     */
    public function get_next_task() {
        $item = $this->get_queue_item();
        if(empty($item)){
            $this->clear_tasks();
        }else{
            $this->reduce_queue();
        }
        return $item;
    }

    /**
     * Get the next item off the queue list
     * @return mixed
     */
    public function get_queue_item(){
        $item = null;
        $tasks = get_site_option($this->cron_queue_key, null);
        if(! empty($tasks) && is_array($tasks)){
            $item = current($tasks);
        }

        return $item;
    }

    /**
     * Execute the task
     * @param  mixed $item
     * @return boolean
     */
    public function do_task($item){
        if(is_array($item)){
            if(!empty($item['hook']) && !empty($item['args'])){
                do_action($item['hook'], $item['args']);
            }
        }
        file_put_contents(__DIR__ . '/test.log', json_encode($item) . PHP_EOL, FILE_APPEND);
        return true;
    }

    /**
     * Pop the first task off the queue
     */
    public function reduce_queue(){
        $tasks = get_site_option($this->cron_queue_key, null);
        if(! empty($tasks) && is_array($tasks)){
            $top = array_shift($tasks);
            if(! empty($tasks)){
                update_site_option($this->cron_queue_key, $tasks);
            }
        }
        if(empty($tasks))
            $this->clear_tasks();
    }

    /**
     * Delete the queue database record and stop the cron
     */
    public function clear_tasks(){
        delete_site_option($this->cron_queue_key);
        wp_clear_scheduled_hook($this->cron_hook);
    }
}
