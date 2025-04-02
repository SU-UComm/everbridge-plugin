<?php
/**
 * Plugin Name: Everbridge
 * Description: Create posts based on notifications from the Everbridge mass notification platform
 * Version: 1.0
 * Author: JB Christy, University Communications, Stanford University
 * Author URI: http://ucomm.stanford.edu/webteam
 * License: GPL2
 */

if ( !class_exists( 'Everbridge' ) ) {
class Everbridge {

  /******************************************************************************
   * 
   * Class / Instance Variables
   * 
   ******************************************************************************/

  /** @var string $plugin name of the plugin */
  private $plugin     = ''; // initialized in the constructor

  /** @var string $ver plugin version */
  const VERSION       = '1.0';

  /** @var Everbridge singleton instance of this class */
  protected static $instance = null;

  /** @var string $option_name name of option variable */
  const OPTION_NAME   = 'everbridge_opts';

  /** @var array plugin options */
  protected $options  = null;

  /** @var string API_NAMESPACE - our routes are based on /wp-json/emergency/ */
  const API_NAMESPACE = 'everbridge/';

  /** @var string API_VERSION - our reoutes will be reached via /wp-json/emergency/v1/ */
  const API_VERSION   = 'v1';


  /******************************************************************************
   *
   * Configure API
   *
   *****************************************************************************/

  /**
   * Add API route to accept the POST from Everbridge
   * Invoked via the rest_api_init action - @see __construct
   */
  public function register_routes() {
    register_rest_route( self::API_NAMESPACE . self::API_VERSION, '/notification', [
        'methods'             => WP_REST_Server::CREATABLE
      , 'callback'            => [ $this, 'create_alert' ]
      , 'permission_callback' => [ $this, 'verify_credentials' ]
    ] );
  }

  /**
   * Create a post from the data POSTed to our endpoint
   * Invoked as a callback via register_rest_route()
   *
   * @return string|WP_REST_Response
   */
  public function create_alert() {
    $alert = file_get_contents('php://input');
    $alert = json_decode( $alert );

    $alert_categories = [
        get_cat_ID( 'alertsu' )
      , get_cat_ID( 'alert'   )
    ];
    $new_post = [
        'post_title'    => sanitize_text_field( $alert->title )
      , 'post_content'  => wp_kses_post( $alert->body )
      , 'post_author'   => $this->options[ 'authorid' ]
      , 'post_status'   => 'publish'
      , 'post_category' => $alert_categories
      , 'tags_input'    => [ 'Active' ]
    ];

    $post_id = wp_insert_post( $new_post );
    if ( is_wp_error( $post_id ) ) return new WP_REST_Response( $post_id, 500 );

    return rest_ensure_response( "Created post {$post_id}" );
  }

  /**
   * Verify that the username and password sent with the POST match what we're expecting
   * Invoked as a callback via register_rest_route() - @see register_routes
   *
   * @return bool|WP_Error
   */
  public function verify_credentials() {
    $user = $_SERVER[ 'PHP_AUTH_USER' ];
    $pw   = $_SERVER[ 'PHP_AUTH_PW'   ];

    if ( empty( $user ) ) {
      return new WP_Error( 'rest_forbidden', esc_html__( 'No username provided', 'stanford_text_domain' ), [ 'status' => 401 ] );
    }
    if ( empty( $pw   ) ) {
      return new WP_Error( 'rest_forbidden', esc_html__( 'No password provided', 'stanford_text_domain' ), [ 'status' => 401 ] );
    }
    if ( $user != $this->options[ 'username' ] || $pw != $this->options[ 'password' ] ) {
      return new WP_Error( 'rest_forbidden', esc_html__( 'Username / password mismatch', 'stanford_text_domain' ), [ 'status' => 401 ] );
    }

    // all's well, allow the action
    return TRUE;
  }


  /******************************************************************************
   *
   * Manage plugin options / settings
   *
   ******************************************************************************/


  /**
   * Register our options variable
   * Add callbacks to emit the markup to manage our options
   * Invoked via the admin_init action - @see __construct
   */
  public function register_settings() {
    register_setting( self::OPTION_NAME , self::OPTION_NAME, [ $this, 'sanitize_options' ] );
    add_settings_section('everbridge_credentials',  'Credentials',  [ $this, 'credentials_section'  ], 'everbridge');
    add_settings_section('everbridge_post_options', 'Post Options', [ $this, 'post_options_section' ], 'everbridge');
  }

  /**
   * Add our settings page to the Settings menu
   * Invoked via the admin_menu action - @see __construct
   */
  public function add_settings_menu() {
    add_options_page( 'Everbridge Settings', 'Everbridge', 'manage_options', 'everbridge', [ $this, 'settings_page' ] );
  }

  /**
   * Emit the markup for the settings page
   * Invoked as a callback via add_options_page() - @see add_settings_menu
   */
  public function settings_page() {
    $route = get_site_url() . '/wp-json/' . self::API_NAMESPACE . self::API_VERSION . '/notification';
?>
<div class="wrap">
  <h2>Everbridge Settings</h2>
  <div class="notice notice-info">
    <p>Notifications from Everbridge should post to <strong><?php echo $route; ?></strong></p>
  </div>
  <hr/>
  <form method="post" action="options.php">
    <?php settings_fields( self::OPTION_NAME ); ?>
    <?php do_settings_sections( 'everbridge'  ); ?>
    <?php submit_button(); ?>
  </form>
</div>
<?php
  }

  /**
   * Emit the markup for the username and password settings
   * Invoked as a callback via add_settings_section - @see register_settings
   */
  public function credentials_section() {
?>
    <p>Enter the username and password that Everbridge will send with each alert</p>
    <table class="form-table">
      <tr>
        <th scope="row">
          <label for="<?php echo self::OPTION_NAME; ?>[username]">Username:</label>
        </th>
        <td>
          <input type="text" name="<?php echo self::OPTION_NAME; ?>[username]" value="<?php echo $this->options[ 'username' ] ?>" style="width: 15em;" />
        </td>
      </tr>
      <tr>
        <th scope="row">
          <label for="<?php echo self::OPTION_NAME; ?>[password]">Password:</label>
        </th>
        <td>
          <input type="text" name="<?php echo self::OPTION_NAME; ?>[password]" value="<?php echo $this->options[ 'password' ] ?>" style="width: 15em;" />
        </td>
      </tr>
    </table>
    <hr/>
<?php
  }

  /**
   * Emit the markup for the authorid setting
   * Invoked as a callback via add_settings_section - @see register_settings
   */
  public function post_options_section() {
    $users = get_users( [
        'role__in' => [ 'administrator', 'editor' ]
    ] );
    $categories = get_categories( [
        'hide_empty' => FALSE
      , 'orderby' => 'term_group'
    ] );
?>
    <table class="form-table">
      <tr>
        <th scope="row">
          <label for="<?php echo self::OPTION_NAME; ?>[authorid]">Post author:</label>
        </th>
        <td>
          <select name="<?php echo self::OPTION_NAME; ?>[authorid]">
          <?php foreach ( $users as $user ) { ?>
            <option value="<?php echo $user->ID; ?>" <?php if ( $user->ID == $this->options[ 'authorid' ] ) echo "selected"; ?>><?php echo $user->get( 'display_name' ); ?></option>
          <?php } ?>
          </select><br/>
          <p><em>Select the user who will be the author of alert posts</em>.</p>
        </td>
      </tr>
    </table>
    <hr/>
<?php
  }

  /**
   * Validate / sanitize option settings
   *
   * @param array $input
   *
   * @return array
   */
  public function sanitize_options( $input ) {
    $options = [
        'username' => sanitize_text_field( $input[ 'username' ] )
      , 'password' => sanitize_text_field( $input[ 'password' ] )
      , 'authorid' => absint( $input[ 'authorid' ] )
    ];
    return $options;
  }

  /**
   * Provide a link to the settings page on the plugins page
   * Invoked via the plugin_action_links_{$plugin} filter
   *
   * @param  array $links
   * @return array
   */
  public function add_settings_link( $links ) {
    $links[] = "<a href=\"options-general.php?page=everbridge\">Settings</a>";
    return $links;
  }


  /******************************************************************************
   *
   * Class Setup
   *
   ******************************************************************************/


  /**
   * Everbridge constructor
   *
   * Initialize the singleton instance
   * Add action and filter hooks
   */
  protected function __construct() {
    // initialize the singleton instance
    $this->plugin = plugin_basename( __FILE__ );

    $this->options = get_option( self::OPTION_NAME, [
        'username' => ''
      , 'password' => ''
      , 'authorid' => 1
    ] );

    // add hooks
    add_action( 'rest_api_init', [ $this, 'register_routes'   ] );

    if ( is_admin())  {
      add_action( 'admin_init', [ $this, 'register_settings' ] );
      add_action( 'admin_menu', [ $this, 'add_settings_menu' ] );

      add_filter( "plugin_action_links_{$this->plugin}", [$this, 'add_settings_link' ] );
    }
  }

  /**
   * Create singleton instance, if necessary.
   *
   * @return Everbridge
   */
  public static function init() {
    if ( !is_a( self::$instance, __CLASS__ ) ) {
      self::$instance = new Everbridge();
    }
    return self::$instance;
  }
}
}

/** Initialize the singleton instance */
global $everbridge_plugin;
if ( !isset( $everbridge_plugin ) ) {
  $everbridge_plugin = Everbridge::init();
}