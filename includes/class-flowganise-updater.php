<?php
class Flowganise_Updater {
    private $plugin_slug = 'flowganise';
    private $plugin_basename;
    private $version;
    private $github_repo = 'Flowganise/flowganise-wp-release';
    private $github_api = 'https://api.github.com/repos/Flowganise/flowganise-wp-release';

    public function __construct($plugin_file, $version) {
        $this->plugin_basename = plugin_basename($plugin_file);
        $this->version = $version;
  
        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_update'));
        add_filter('plugins_api', array($this, 'plugin_info'), 20, 3);

        // Clear plugin update transient on activation
        register_activation_hook($plugin_file, function() {
            delete_site_transient('update_plugins');
        });
    }

    public function check_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        $response = wp_remote_get($this->github_api . '/releases/latest', array(
            'headers' => array(
                'Accept' => 'application/vnd.github.v3+json',
            ),
            'timeout' => 10,
        ));

        if (is_wp_error($response)) {
            error_log('Flowganise Updater - GitHub API Error: ' . $response->get_error_message());
            return $transient;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            error_log('Flowganise Updater - GitHub API returned code: ' . $code);
            return $transient;
        }

        $release = json_decode(wp_remote_retrieve_body($response));
        if (empty($release)) {
            error_log('Flowganise Updater - No release data found');
            return $transient;
        }

        if (version_compare($this->version, $release->tag_name, '<')) {

            $plugin = array(
                'slug' => $this->plugin_slug,
                'plugin' => $this->plugin_basename,
                'new_version' => $release->tag_name,
                'url' => $release->html_url,
                'package' => $release->zipball_url,
                'icons' => array(),
                'banners' => array(),
                'banners_rtl' => array(),
                'tested' => '6.4.2',
                'requires_php' => '7.2',
                'compatibility' => new stdClass(),
            );

            $transient->response[$this->plugin_basename] = (object) $plugin;
        } else {
            // Make sure the plugin is listed in the no_update property
            $plugin = array(
                'slug' => $this->plugin_slug,
                'plugin' => $this->plugin_basename,
                'new_version' => $this->version,
                'url' => $release->html_url,
                'package' => $release->zipball_url,
                'icons' => array(),
                'banners' => array(),
                'banners_rtl' => array(),
                'tested' => '6.4.2',
                'requires_php' => '7.2',
                'compatibility' => new stdClass(),
            );
            $transient->no_update[$this->plugin_basename] = (object) $plugin;
        }

        return $transient;
    }

    public function plugin_info($false, $action, $response) {
        if ($response->slug !== $this->plugin_slug) {
            return $false;
        }

        $response = wp_remote_get($this->github_api . '/releases/latest', array(
            'headers' => array(
                'Accept' => 'application/vnd.github.v3+json',
            ),
            'timeout' => 10,
        ));

        if (is_wp_error($response)) {
            error_log('Flowganise Updater - GitHub API Error in plugin_info: ' . $response->get_error_message());
            return $false;
        }

        $release = json_decode(wp_remote_retrieve_body($response));
        if (empty($release)) {
            error_log('Flowganise Updater - No release data found in plugin_info');
            return $false;
        }

        $plugin_info = array(
            'name' => 'Flowganise Analytics',
            'slug' => $this->plugin_slug,
            'version' => $release->tag_name,
            'author' => '<a href="https://flowganise.com">Flowganise</a>',
            'homepage' => 'https://flowganise.com',
            'requires' => '5.0',
            'tested' => get_bloginfo('version'),
            'last_updated' => $release->published_at,
            'download_link' => $release->zipball_url,
            'sections' => array(
                'description' => $this->get_description(),
                'changelog' => nl2br($release->body)
            ),
            'banners' => array(),
            'icons' => array(),
        );

        return (object) $plugin_info;
    }

    private function get_description() {
        return '
            <p>Integrates Flowganise Analytics with your WordPress site.</p>
            <h4>Features</h4>
            <ul>
                <li>One-click connection with your Flowganise account</li>
                <li>Automatic tracking script installation</li>
                <li>Simple WordPress admin interface</li>
                <li>No configuration needed</li>
            </ul>
        ';
    }
}