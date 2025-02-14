<?php

class LCD_County_Map_Election_Integration {
    private static $instance = null;
    private $results_table;
    private $candidates_table;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if (!$this->is_election_plugin_active()) {
            return;
        }

        global $wpdb;
        $this->results_table = $wpdb->prefix . 'election_results';
        $this->candidates_table = $wpdb->prefix . 'election_candidates';

        // Add filters for map integration
        add_filter('lcd_county_map_config', array($this, 'extend_map_config'), 10, 2);
        add_filter('lcd_county_map_data', array($this, 'extend_map_data'), 10, 2);
        
        // Add our scripts
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));

        // Add AJAX handlers
        add_action('wp_ajax_get_election_results', array($this, 'ajax_get_election_results'));
        add_action('wp_ajax_nopriv_get_election_results', array($this, 'ajax_get_election_results'));
        add_action('wp_ajax_get_election_votes', array($this, 'ajax_get_election_votes'));
        add_action('wp_ajax_nopriv_get_election_votes', array($this, 'ajax_get_election_votes'));
    }

    private function is_election_plugin_active() {
        return class_exists('LCD_Election_Results');
    }

    public function extend_map_config($config, $atts) {
        if (!$this->is_election_plugin_active()) {
            return $config;
        }

        // Add our configuration
        $config['show_election_data'] = isset($atts['show_election_data']) ? 
            filter_var($atts['show_election_data'], FILTER_VALIDATE_BOOLEAN) : false;
        
        return $config;
    }

    public function extend_map_data($data, $config) {
        if (!$this->is_election_plugin_active()) {
            return $data;
        }

        // Check if we're using the election map shortcode or if election data is explicitly enabled
        global $post;
        $is_election_map = is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'lcd_election_map');
        
        if (!$is_election_map && empty($config['show_election_data'])) {
            return $data;
        }

        $elections = $this->get_available_elections();
        $races = $this->get_available_races();
        $party_colors = get_option('lcd_party_colors', array());

        // Add election data to the map configuration
        $data['election_data'] = array(
            'enabled' => true,
            'elections' => $elections,
            'races' => $races,
            'party_colors' => $party_colors
        );

        // Ensure our script gets the data
        add_action('wp_footer', function() use ($elections, $races, $party_colors) {
            ?>
            <script type="text/javascript">
                var lcdElectionData = {
                    enabled: true,
                    elections: <?php echo json_encode($elections); ?>,
                    races: <?php echo json_encode($races); ?>,
                    partyColors: <?php echo json_encode($party_colors); ?>
                };
            </script>
            <?php
        }, 5);

        return $data;
    }

    public function enqueue_scripts() {
        if (!$this->is_election_plugin_active()) {
            return;
        }

        global $post;
        if (!is_a($post, 'WP_Post')) {
            return;
        }

        // Check if either the election map shortcode exists or the precinct map with election data
        if (has_shortcode($post->post_content, 'lcd_election_map') || 
            (has_shortcode($post->post_content, 'lcd_precinct_map') && 
             strpos($post->post_content, 'show_election_data="true"') !== false)) {
            
            // Ensure D3.js is loaded
            wp_enqueue_script(
                'lcd-d3',
                'https://d3js.org/d3.v7.min.js',
                array(),
                '7.0.0',
                true
            );

            // Enqueue our script
            wp_enqueue_script(
                'lcd-election-map',
                plugins_url('assets/js/election-map.js', dirname(__FILE__)),
                array('jquery', 'lcd-map-script', 'lcd-d3'),
                '1.0.0',
                true
            );

            wp_localize_script('lcd-election-map', 'lcd_election_map', array(
                'nonce' => wp_create_nonce('lcd_election_map'),
                'ajaxurl' => admin_url('admin-ajax.php')
            ));
        }
    }

    public function get_available_elections() {
        global $wpdb;
        return $wpdb->get_col("SELECT DISTINCT election_date FROM {$this->candidates_table} ORDER BY election_date DESC");
    }

    public function get_available_races() {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT DISTINCT race_name, election_date 
            FROM {$this->candidates_table} 
            ORDER BY election_date DESC, race_name ASC"
        );
    }

    private function get_registered_voters_data() {
        global $wpdb;
        $voters_table = $wpdb->prefix . 'election_voters';
        
        // Debug: Check table and sample data
        error_log('LCD County Map: Checking voter data table');
        $sample = $wpdb->get_results("SELECT * FROM {$voters_table} LIMIT 1");
        error_log('LCD County Map: Sample voter record: ' . print_r($sample, true));
        
        // Get count of both active and inactive registered voters by precinct
        $query = "SELECT 
                    precinct_code,
                    precinct_part,
                    status_code,
                    COUNT(*) as voter_count 
                 FROM {$voters_table} 
                 GROUP BY precinct_code, precinct_part, status_code";
        
        error_log('LCD County Map: Voter query: ' . $query);
        $results = $wpdb->get_results($query, ARRAY_A);
        error_log('LCD County Map: Raw voter results: ' . print_r($results, true));
        
        $voter_data = array();
        foreach ($results as $row) {
            // Convert precinct code from '0040' format to '40' to match election results
            $precinct_key = ltrim($row['precinct_code'], '0');
            
            if (!isset($voter_data[$precinct_key])) {
                $voter_data[$precinct_key] = array(
                    'active' => 0,
                    'inactive' => 0,
                    'total' => 0
                );
            }
            
            $count = intval($row['voter_count']);
            if ($row['status_code'] === 'Active') {
                $voter_data[$precinct_key]['active'] += $count;
            } else {
                $voter_data[$precinct_key]['inactive'] += $count;
            }
            $voter_data[$precinct_key]['total'] += $count;
        }
        
        error_log('LCD County Map: Final voter data: ' . print_r($voter_data, true));
        return $voter_data;
    }

    public function get_precinct_results($election_date, $race_name) {
        global $wpdb;
        
        // Get registered voters data instead of population
        $voter_data = $this->get_registered_voters_data();
        
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                r.precinct_number,
                r.precinct_name,
                c.candidate_name,
                c.party,
                r.votes,
                c.race_name
            FROM {$this->results_table} r
            JOIN {$this->candidates_table} c ON r.candidate_id = c.id
            WHERE c.election_date = %s
            AND c.race_name = %s
            ORDER BY r.precinct_number, r.votes DESC",
            $election_date,
            $race_name
        ));

        // Format results by precinct
        $formatted = array();
        foreach ($results as $row) {
            $precinct_number = strval($row->precinct_number);
            if (!isset($formatted[$precinct_number])) {
                $voter_counts = isset($voter_data[$precinct_number]) ? 
                    $voter_data[$precinct_number] : 
                    array('active' => 0, 'inactive' => 0, 'total' => 0);
                
                $formatted[$precinct_number] = array(
                    'precinct_name' => $row->precinct_name,
                    'race_name' => $row->race_name,
                    'registered_voters' => $voter_counts['active'], // Use active voters for turnout
                    'active_voters' => $voter_counts['active'],
                    'inactive_voters' => $voter_counts['inactive'],
                    'total_registered' => $voter_counts['total'],
                    'candidates' => array()
                );
            }
            $formatted[$precinct_number]['candidates'][] = array(
                'name' => $row->candidate_name,
                'party' => $row->party,
                'votes' => intval($row->votes)
            );
        }

        return $formatted;
    }

    public function ajax_get_election_results() {
        check_ajax_referer('lcd_election_map', 'nonce');

        $election_date = sanitize_text_field($_POST['election_date']);
        $race_name = sanitize_text_field($_POST['race_name']);

        $results = $this->get_precinct_results($election_date, $race_name);
        wp_send_json_success($results);
    }

    public function ajax_get_election_votes() {
        check_ajax_referer('lcd_election_map', 'nonce');

        $election_date = sanitize_text_field($_POST['election_date']);
        $voter_data = $this->get_registered_voters_data();

        global $wpdb;

        // Get all races for this election
        $races = $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT race_name 
            FROM {$this->candidates_table} 
            WHERE election_date = %s
        ", $election_date));

        // Get total votes per precinct across all races
        $precinct_votes = array();
        foreach ($races as $race) {
            $results = $this->get_precinct_results($election_date, $race);
            foreach ($results as $precinct_number => $data) {
                if (!isset($precinct_votes[$precinct_number])) {
                    $voter_counts = isset($voter_data[$precinct_number]) ? 
                        $voter_data[$precinct_number] : 
                        array('active' => 0, 'inactive' => 0, 'total' => 0);

                    $precinct_votes[$precinct_number] = array(
                        'votes' => 0,
                        'active_voters' => $voter_counts['active'],
                        'inactive_voters' => $voter_counts['inactive'],
                        'total_registered' => $voter_counts['total']
                    );
                }
                // Sum up all votes for this precinct in this race
                $total_votes = array_reduce($data['candidates'], function($sum, $candidate) {
                    return $sum + intval($candidate['votes']);
                }, 0);
                // Use the highest vote count among races (since some precincts might not participate in all races)
                $precinct_votes[$precinct_number]['votes'] = max(
                    $precinct_votes[$precinct_number]['votes'],
                    $total_votes
                );
            }
        }

        error_log('LCD County Map: Final precinct votes data: ' . print_r($precinct_votes, true));
        wp_send_json_success($precinct_votes);
    }
} 