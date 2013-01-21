<?php
/**
 * This is an HTTP client class for Cloud Files.  It uses PHP's cURL module
 * to handle the actual HTTP request/response.  This is NOT a generic HTTP
 * client class and is only used to abstract out the HTTP communication for
 * the PHP Cloud Files API.
 *
 * This module was designed to re-use existing HTTP(S) connections between
 * subsequent operations.  For example, performing multiple PUT operations
 * will re-use the same connection.
 *
 * This modules also provides support for streaming content into and out
 * of Cloud Files.  The majority (all?) of the PHP HTTP client modules expect
 * to read the server's response into a string variable.  This will not work
 * with large files without killing your server.  Methods like,
 * get_object_to_stream() and put_object() take an open filehandle
 * argument for streaming data out of or into Cloud Files.
 *
 * Requres PHP 5.x (for Exceptions and OO syntax)
 *
 * See COPYING for license information.
 *
 * @author Eric "EJ" Johnson <ej@racklabs.com>
 * @author Aaron Schulz <aschulz4587@gmail.com>
 * @copyright Copyright (c) 2008, Rackspace US, Inc.
 * @package php-cloudfiles-http
 */
/**
 */
require_once( dirname( __FILE__ ) . "/cloudfiles_exceptions.php" );

define( "PHP_CF_VERSION", "1.7.10" );
define( "USER_AGENT", sprintf( "PHP-CloudFiles/%s", PHP_CF_VERSION ) );
define( "MAX_HEADER_NAME_LEN", 128 );
define( "MAX_HEADER_VALUE_LEN", 256 );
define( "ACCOUNT_CONTAINER_COUNT", "X-Account-Container-Count" );
define( "ACCOUNT_BYTES_USED", "X-Account-Bytes-Used" );
define( "CONTAINER_OBJ_COUNT", "X-Container-Object-Count" );
define( "CONTAINER_BYTES_USED", "X-Container-Bytes-Used" );
define( "MANIFEST_HEADER", "X-Object-Manifest" );
define( "METADATA_HEADER_PREFIX", "X-Object-Meta-" );
define( "CONTENT_HEADER_PREFIX", "Content-" );
define( "X_CONTENT_HEADER_PREFIX", "X-Content-" );
define( "ACCESS_CONTROL_HEADER_PREFIX", "Access-Control-" );
define( "ORIGIN_HEADER", "Origin" );
define( "CDN_URI", "X-CDN-URI" );
define( "CDN_SSL_URI", "X-CDN-SSL-URI" );
define( "CDN_STREAMING_URI", "X-CDN-Streaming-URI" );
define( "CDN_ENABLED", "X-CDN-Enabled" );
define( "CDN_LOG_RETENTION", "X-Log-Retention" );
define( "CDN_ACL_USER_AGENT", "X-User-Agent-ACL" );
define( "CDN_ACL_REFERRER", "X-Referrer-ACL" );
define( "CDN_TTL", "X-TTL" );
define( "CDNM_URL", "X-CDN-Management-Url" );
define( "STORAGE_URL", "X-Storage-Url" );
define( "AUTH_TOKEN", "X-Auth-Token" );
define( "AUTH_USER_HEADER", "X-Auth-User" );
define( "AUTH_KEY_HEADER", "X-Auth-Key" );
define( "CDN_EMAIL", "X-Purge-Email" );
define( "DESTINATION", "Destination" );
define( "ETAG_HEADER", "ETag" );
define( "LAST_MODIFIED_HEADER", "Last-Modified" );
define( "CONTENT_TYPE_HEADER", "Content-Type" );
define( "CONTENT_LENGTH_HEADER", "Content-Length" );
define( "USER_AGENT_HEADER", "User-Agent" );

/**
 * HTTP/cURL wrapper for Cloud Files
 *
 * This class should not be used directly.  It's only purpose is to abstract
 * out the HTTP communication from the main API.
 *
 * @package php-cloudfiles-http
 */
class CF_Http {
	private $error_str;
	private $dbug;
	private $cabundle_path;
	private $api_version;

	# Authentication instance variables
	#
    private $storage_url;
	private $cdnm_url;
	private $auth_token;

	# Request/response variables
	#
    private $response_status;
	private $response_reason;
	private $connections;

	# Variables used for content/header callbacks
	#
    private $_user_read_progress_callback_func;
	private $_user_write_progress_callback_func;
	private $_write_callback_type;
	private $_text_list;
	private $_account_container_count;
	private $_account_bytes_used;
	private $_container_object_count;
	private $_container_bytes_used;
	private $_obj_etag;
	private $_obj_last_modified;
	private $_obj_content_type;
	private $_obj_content_length;
	private $_obj_metadata;
	private $_obj_headers;
	private $_obj_manifest;
	private $_obj_write_resource;
	private $_obj_write_string;
	private $_cdn_enabled;
	private $_cdn_ssl_uri;
	private $_cdn_streaming_uri;
	private $_cdn_uri;
	private $_cdn_ttl;
	private $_cdn_log_retention;
	private $_cdn_acl_user_agent;
	private $_cdn_acl_referrer;

	public function __construct( $api_version ) {
		$this->dbug = False;
		$this->cabundle_path = NULL;
		$this->api_version = $api_version;
		$this->error_str = NULL;

		$this->storage_url = NULL;
		$this->cdnm_url = NULL;
		$this->auth_token = NULL;

		$this->response_status = NULL;
		$this->response_reason = NULL;

		# Curl connections array - since there is no way to "re-set" the
		# connection paramaters for a cURL handle, we keep an array of
		# the unique use-cases and funnel all of those same type
		# requests through the appropriate curl connection.
		#
        $this->connections = array(
			"GET_CALL" => NULL, # GET objects/containers/lists
			"PUT_OBJ" => NULL, # PUT object
			"HEAD" => NULL, # HEAD requests
			"PUT_CONT" => NULL, # PUT container
			"DEL_POST" => NULL, # DELETE containers/objects, POST objects
			"COPY" => null, # COPY objects
		);

		$this->_user_read_progress_callback_func = NULL;
		$this->_user_write_progress_callback_func = NULL;
		$this->_write_callback_type = NULL;
		$this->_text_list = array( );
		$this->_return_list = NULL;
		$this->_account_container_count = 0;
		$this->_account_bytes_used = 0;
		$this->_container_object_count = 0;
		$this->_container_bytes_used = 0;
		$this->_obj_write_resource = NULL;
		$this->_obj_write_string = "";
		$this->_obj_etag = NULL;
		$this->_obj_last_modified = NULL;
		$this->_obj_content_type = NULL;
		$this->_obj_content_length = NULL;
		$this->_obj_metadata = array( );
		$this->_obj_manifest = NULL;
		$this->_obj_headers = array( );
		$this->_cdn_enabled = NULL;
		$this->_cdn_ssl_uri = NULL;
		$this->_cdn_streaming_uri = NULL;
		$this->_cdn_uri = NULL;
		$this->_cdn_ttl = NULL;
		$this->_cdn_log_retention = NULL;
		$this->_cdn_acl_user_agent = NULL;
		$this->_cdn_acl_referrer = NULL;

		# The OS list with a PHP without an updated CA File for CURL to
		# connect to SSL Websites. It is the first 3 letters of the PHP_OS
		# variable.
		$OS_CAFILE_NONUPDATED = array(
			"win", "dar"
		);

		if ( in_array( (strtolower( substr( PHP_OS, 0, 3 ) ) ), $OS_CAFILE_NONUPDATED ) ) {
			$this->ssl_use_cabundle();
		}
	}

	public function ssl_use_cabundle( $path = NULL ) {
		if ( $path ) {
			$this->cabundle_path = $path;
		} else {
			$this->cabundle_path = dirname( __FILE__ ) . "/share/cacert.pem";
		}
		if ( !file_exists( $this->cabundle_path ) ) {
			throw new IOException( "Could not use CA bundle: " . $this->cabundle_path );
		}
		return;
	}

	# Uses separate cURL connection to authenticate

	#
    public function authenticate( $user, $pass, $host = NULL ) {
		$path = array( );
		$headers = array(
			sprintf( "%s: %s", 'Content-type', 'application/json' ),
		);
		$usertenant = explode(':', $user);
		$data = sprintf('{"auth":{"passwordCredentials":{"username": "%s", "password": "%s"},"tenantId": "%s"}}', $usertenant[0], $pass, $usertenant[1]);
		
		$path[] = $host;
        $path[] = 'tokens';
		$url = implode( "/", $path );

		$curl_ch = curl_init();
		if ( !is_null( $this->cabundle_path ) ) {
			curl_setopt( $curl_ch, CURLOPT_SSL_VERIFYPEER, True );
			curl_setopt( $curl_ch, CURLOPT_CAINFO, $this->cabundle_path );
		}
		
        error_log('auth url ' . $url);
        error_log('data ' . $data);
		curl_setopt( $curl_ch, CURLOPT_VERBOSE, $this->dbug );
		curl_setopt( $curl_ch, CURLOPT_FOLLOWLOCATION, 1 );
		curl_setopt( $curl_ch, CURLOPT_MAXREDIRS, 4 );
		curl_setopt( $curl_ch, CURLOPT_HEADER, 0 );
		curl_setopt( $curl_ch, CURLOPT_HTTPHEADER, $headers );
		curl_setopt( $curl_ch, CURLOPT_USERAGENT, USER_AGENT );
		curl_setopt( $curl_ch, CURLOPT_RETURNTRANSFER, TRUE );
		curl_setopt( $curl_ch, CURLOPT_HEADERFUNCTION, array( &$this, '_auth_hdr_cb' ) );
		curl_setopt( $curl_ch, CURLOPT_CONNECTTIMEOUT, 10 );
		curl_setopt( $curl_ch, CURLOPT_TIMEOUT, 10 );
		curl_setopt( $curl_ch, CURLOPT_URL, $url );
		curl_setopt( $curl_ch, CURLOPT_POST, 1); 
        curl_setopt( $curl_ch, CURLOPT_POSTFIELDS, $data); 
        #curl_exec( $curl_ch );
        $raw = curl_exec( $curl_ch );
		curl_close( $curl_ch );

        error_log('raw: ' . $raw);
        $response = json_decode($raw);
        #error_log('response: ' . $response);
        $this->auth_token = $response->{'access'}->{'token'}->{'id'};
        
        foreach($response->{'access'}->{'serviceCatalog'} as $catalog_item) {
            if ($catalog_item->{'type'} == "object-store"){
                $this->storage_url = $catalog_item->{'endpoints'}[0]->{'publicURL'};
            }
        }
        error_log('auth_token: ' . $this->auth_token);
        error_log('storage_url: ' . $this->storage_url);
		return array( $this->response_status, $this->response_reason,
			$this->storage_url, $this->cdnm_url, $this->auth_token );
	}

	# (CDN) GET /v1/Account

	#
    public function list_cdn_containers( $enabled_only ) {
		$conn_type = "GET_CALL";
		$url_path = $this->_make_path( "CDN" );

		$this->_write_callback_type = "TEXT_LIST";
		if ( $enabled_only ) {
			$return_code = $this->_send_request( $conn_type, $url_path . '/?enabled_only=true' );
		} else {
			$return_code = $this->_send_request( $conn_type, $url_path );
		}
		if ( !$return_code ) {
			$this->error_str .= ": Failed to obtain valid HTTP response.";
			return array( 0, $this->error_str, array( ) );
		}
		if ( $return_code == 401 ) {
			return array( $return_code, "Unauthorized", array( ) );
		}
		if ( $return_code == 404 ) {
			return array( $return_code, "Account not found.", array( ) );
		}
		if ( $return_code == 204 ) {
			return array( $return_code, "Account has no CDN enabled Containers.",
				array( ) );
		}
		if ( $return_code == 200 ) {
			$this->create_array();
			return array( $return_code, $this->response_reason, $this->_text_list );
		}
		$this->error_str = "Unexpected HTTP response: " . $this->response_reason;
		return array( $return_code, $this->error_str, array( ) );
	}

	# (CDN) DELETE /v1/Account/Container or /v1/Account/Container/Object

	#
    public function purge_from_cdn( $path, $email = null ) {
		if ( !$path ) {
			throw new SyntaxException( "Path not set" );
		}
		$url_path = $this->_make_path( "CDN", NULL, $path );
		if ( $email ) {
			$hdrs = array( CDN_EMAIL => $email );
			$return_code = $this->_send_request( "DEL_POST", $url_path, $hdrs, "DELETE" );
		} else {
			$return_code = $this->_send_request( "DEL_POST", $url_path, null, "DELETE" );
		}
		return $return_code;
	}

	# (CDN) POST /v1/Account/Container

	public function update_cdn_container(
		$container_name, $ttl = 86400, $cdn_log_retention = False,
		$cdn_acl_user_agent = "", $cdn_acl_referrer = ""
	) {
		$container_name = (string)$container_name;
		if ( $container_name === "" ) {
			throw new SyntaxException( "Container name not set." );
		}

		$url_path = $this->_make_path( "CDN", $container_name );
		$hdrs = array(
			CDN_ENABLED        => "True",
			CDN_TTL            => $ttl,
			CDN_LOG_RETENTION  => $cdn_log_retention ? "True" : "False",
			CDN_ACL_USER_AGENT => $cdn_acl_user_agent,
			CDN_ACL_REFERRER   => $cdn_acl_referrer,
		);
		$return_code = $this->_send_request( "DEL_POST", $url_path, $hdrs, "POST" );
		if ( $return_code == 401 ) {
			$this->error_str = "Unauthorized";
			return array( $return_code, $this->error_str, NULL );
		}
		if ( $return_code == 404 ) {
			$this->error_str = "Container not found.";
			return array( $return_code, $this->error_str, NULL );
		}
		if ( $return_code != 202 ) {
			$this->error_str = "Unexpected HTTP response: " . $this->response_reason;
			return array( $return_code, $this->error_str, NULL );
		}
		return array( $return_code, "Accepted", $this->_cdn_uri, $this->_cdn_ssl_uri );
	}

	# (CDN) PUT /v1/Account/Container

	#
    public function add_cdn_container( $container_name, $ttl = 86400 ) {
		$container_name = (string)$container_name;
		if ( $container_name === "" ) {
			throw new SyntaxException( "Container name not set." );
		}

		$url_path = $this->_make_path( "CDN", $container_name );
		$hdrs = array(
			CDN_ENABLED => "True",
			CDN_TTL => $ttl,
		);
		$return_code = $this->_send_request( "PUT_CONT", $url_path, $hdrs );
		if ( $return_code == 401 ) {
			$this->error_str = "Unauthorized";
			return array( $return_code, $this->response_reason, False );
		}
		if ( !in_array( $return_code, array( 201, 202 ) ) ) {
			$this->error_str = "Unexpected HTTP response: " . $this->response_reason;
			return array( $return_code, $this->response_reason, False );
		}
		return array( $return_code, $this->response_reason, $this->_cdn_uri,
			$this->_cdn_ssl_uri );
	}

	# (CDN) POST /v1/Account/Container

	#
    public function remove_cdn_container( $container_name ) {
		$container_name = (string)$container_name;
		if ( $container_name === "" ) {
			throw new SyntaxException( "Container name not set." );
		}

		$url_path = $this->_make_path( "CDN", $container_name );
		$hdrs = array( CDN_ENABLED => "False" );
		$return_code = $this->_send_request( "DEL_POST", $url_path, $hdrs, "POST" );
		if ( $return_code == 401 ) {
			$this->error_str = "Unauthorized";
			return array( $return_code, $this->error_str );
		}
		if ( $return_code == 404 ) {
			$this->error_str = "Container not found.";
			return array( $return_code, $this->error_str );
		}
		if ( $return_code != 202 ) {
			$this->error_str = "Unexpected HTTP response: " . $this->response_reason;
			return array( $return_code, $this->error_str );
		}
		return array( $return_code, "Accepted" );
	}

	# (CDN) HEAD /v1/Account

	#
    public function head_cdn_container( $container_name ) {
		$container_name = (string)$container_name;
		if ( $container_name === "" ) {
			throw new SyntaxException( "Container name not set." );
		}

		$conn_type = "HEAD";
		$url_path = $this->_make_path( "CDN", $container_name );
		$return_code = $this->_send_request( $conn_type, $url_path, NULL, "GET", True );

		if ( !$return_code ) {
			$this->error_str .= ": Failed to obtain valid HTTP response.";
			return array( 0, $this->error_str, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL );
		}
		if ( $return_code == 401 ) {
			return array( $return_code, "Unauthorized", NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL );
		}
		if ( $return_code == 404 ) {
			return array( $return_code, "Account not found.", NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL );
		}
		if ( $return_code == 204 ) {
			return array( $return_code, $this->response_reason,
				$this->_cdn_enabled, $this->_cdn_ssl_uri,
				$this->_cdn_streaming_uri,
				$this->_cdn_uri, $this->_cdn_ttl,
				$this->_cdn_log_retention,
				$this->_cdn_acl_user_agent,
				$this->_cdn_acl_referrer
			);
		}
		return array( $return_code, $this->response_reason,
			NULL, NULL, NULL, NULL,
			$this->_cdn_log_retention,
			$this->_cdn_acl_user_agent,
			$this->_cdn_acl_referrer,
			NULL
		);
	}

	# GET /v1/Account

	#
    public function list_containers( $limit = 0, $marker = NULL ) {
		$conn_type = "GET_CALL";
		$url_path = $this->_make_path();

		$limit = intval( $limit );
		$params = array( );
		if ( $limit > 0 ) {
			$params[] = "limit=$limit";
		}
		if ( $marker ) {
			$params[] = "marker=" . rawurlencode( $marker );
		}
		if ( !empty( $params ) ) {
			$url_path .= "?" . implode( "&", $params );
		}

		$this->_write_callback_type = "TEXT_LIST";
		$return_code = $this->_send_request( $conn_type, $url_path );

		if ( !$return_code ) {
			$this->error_str .= ": Failed to obtain valid HTTP response.";
			return array( 0, $this->error_str, array( ) );
		}
		if ( $return_code == 204 ) {
			return array( $return_code, "Account has no containers.", array( ) );
		}
		if ( $return_code == 404 ) {
			$this->error_str = "Invalid account name for authentication token.";
			return array( $return_code, $this->error_str, array( ) );
		}
		if ( $return_code == 200 ) {
			$this->create_array();
			return array( $return_code, $this->response_reason, $this->_text_list );
		}
		$this->error_str = "Unexpected HTTP response: " . $this->response_reason;
		return array( $return_code, $this->error_str, array( ) );
	}

	# GET /v1/Account?format=json

	#
    public function list_containers_info( $limit = 0, $marker = NULL ) {
		$conn_type = "GET_CALL";
		$url_path = $this->_make_path() . "?format=json";

		$limit = intval( $limit );
		$params = array( );
		if ( $limit > 0 ) {
			$params[] = "limit=$limit";
		}
		if ( $marker ) {
			$params[] = "marker=" . rawurlencode( $marker );
		}
		if ( !empty( $params ) ) {
			$url_path .= "&" . implode( "&", $params );
		}

		$this->_write_callback_type = "OBJECT_STRING";
		$return_code = $this->_send_request( $conn_type, $url_path );

		if ( !$return_code ) {
			$this->error_str .= ": Failed to obtain valid HTTP response.";
			return array( 0, $this->error_str, array( ) );
		}
		if ( $return_code == 204 ) {
			return array( $return_code, "Account has no containers.", array( ) );
		}
		if ( $return_code == 404 ) {
			$this->error_str = "Invalid account name for authentication token.";
			return array( $return_code, $this->error_str, array( ) );
		}
		if ( $return_code == 200 ) {
			$json_body = json_decode( $this->_obj_write_string, True );
			return array( $return_code, $this->response_reason, $json_body );
		}
		$this->error_str = "Unexpected HTTP response: " . $this->response_reason;
		return array( $return_code, $this->error_str, array( ) );
	}

	# HEAD /v1/Account

	#
    public function head_account() {
		$conn_type = "HEAD";

		$url_path = $this->_make_path();
		$return_code = $this->_send_request( $conn_type, $url_path );

		if ( !$return_code ) {
			$this->error_str .= ": Failed to obtain valid HTTP response.";
			return array( 0, $this->error_str, 0, 0 );
		}
		if ( $return_code == 404 ) {
			return array( $return_code, "Account not found.", 0, 0 );
		}
		if ( $return_code == 204 ) {
			return array( $return_code, $this->response_reason,
				$this->_account_container_count, $this->_account_bytes_used );
		}
		return array( $return_code, $this->response_reason, 0, 0 );
	}

	# PUT /v1/Account/Container

	#
    public function create_container( $container_name ) {
		$container_name = (string)$container_name;
		if ( $container_name === "" ) {
			throw new SyntaxException( "Container name not set." );
		}

		$url_path = $this->_make_path( "STORAGE", $container_name );
		$return_code = $this->_send_request( "PUT_CONT", $url_path );

		if ( !$return_code ) {
			$this->error_str .= ": Failed to obtain valid HTTP response.";
			return False;
		}
		return $return_code;
	}

	# DELETE /v1/Account/Container

	#
    public function delete_container( $container_name ) {
		$container_name = (string)$container_name;
		if ( $container_name === "" ) {
			throw new SyntaxException( "Container name not set." );
		}

		$url_path = $this->_make_path( "STORAGE", $container_name );
		$return_code = $this->_send_request( "DEL_POST", $url_path, array( ), "DELETE" );

		switch ( $return_code ) {
			case 204:
				break;
			case 0:
				$this->error_str .= ": Failed to obtain valid HTTP response.";
				break;
			case 409:
				$this->error_str = "Container must be empty prior to removing it.";
				break;
			case 404:
				$this->error_str = "Specified container did not exist to delete.";
				break;
			default:
				$this->error_str = "Unexpected HTTP return code: $return_code.";
		}
		return $return_code;
	}

	# GET /v1/Account/Container

	#
    public function list_objects(
		$cname, $limit = 0, $marker = NULL, $prefix = NULL, $path = NULL, $delim = NULL
	) {
		$cname = (string)$cname;
		if ( $cname === "" ) {
			$this->error_str = "Container name not set.";
			return array( 0, $this->error_str, array( ) );
		}

		$url_path = $this->_make_path( "STORAGE", $cname );

		$limit = intval( $limit );
		$params = array( );
		if ( $limit > 0 ) {
			$params[] = "limit=$limit";
		}
		if ( $marker ) {
			$params[] = "marker=" . rawurlencode( $marker );
		}
		if ( $prefix ) {
			$params[] = "prefix=" . rawurlencode( $prefix );
		}
		if ( $path ) { // b/c
			$params[] = "path=" . rawurlencode( $path );
		} elseif ( $delim ) {
			$params[] = "delimiter=" . rawurlencode( $delim );
		}
		if ( !empty( $params ) ) {
			$url_path .= "?" . implode( "&", $params );
		}

		$conn_type = "GET_CALL";
		$this->_write_callback_type = "TEXT_LIST";
		$return_code = $this->_send_request( $conn_type, $url_path );

		if ( !$return_code ) {
			$this->error_str .= ": Failed to obtain valid HTTP response.";
			return array( 0, $this->error_str, array( ) );
		}
		if ( $return_code == 204 ) {
			$this->error_str = "Container has no Objects.";
			return array( $return_code, $this->error_str, array( ) );
		}
		if ( $return_code == 404 ) {
			$this->error_str = "Container has no Objects.";
			return array( $return_code, $this->error_str, array( ) );
		}
		if ( $return_code == 200 ) {
			$this->create_array();
			return array( $return_code, $this->response_reason, $this->_text_list );
		}
		$this->error_str = "Unexpected HTTP response code: $return_code";
		return array( 0, $this->error_str, array( ) );
	}

	# GET /v1/Account/Container?format=json

	#
    public function get_objects(
		$cname, $limit = 0, $marker = NULL, $prefix = NULL, $path = NULL, $delim = NULL
	) {
		$cname = (string)$cname;
		if ( $cname === "" ) {
			$this->error_str = "Container name not set.";
			return array( 0, $this->error_str, array( ) );
		}

		$url_path = $this->_make_path( "STORAGE", $cname );

		$limit = intval( $limit );
		$params = array( );
		$params[] = "format=json";
		if ( $limit > 0 ) {
			$params[] = "limit=$limit";
		}
		if ( $marker ) {
			$params[] = "marker=" . rawurlencode( $marker );
		}
		if ( $prefix ) {
			$params[] = "prefix=" . rawurlencode( $prefix );
		}
		if ( $path ) { // b/c
			$params[] = "path=" . rawurlencode( $path );
		} elseif ( $delim ) {
			$params[] = "delimiter=" . rawurlencode( $delim );
		}
		if ( !empty( $params ) ) {
			$url_path .= "?" . implode( "&", $params );
		}

		$conn_type = "GET_CALL";
		$this->_write_callback_type = "OBJECT_STRING";
		$return_code = $this->_send_request( $conn_type, $url_path );

		if ( !$return_code ) {
			$this->error_str .= ": Failed to obtain valid HTTP response.";
			return array( 0, $this->error_str, array( ) );
		}
		if ( $return_code == 204 ) {
			$this->error_str = "Container has no Objects.";
			return array( $return_code, $this->error_str, array( ) );
		}
		if ( $return_code == 404 ) {
			$this->error_str = "Container has no Objects.";
			return array( $return_code, $this->error_str, array( ) );
		}
		if ( $return_code == 200 ) {
			$json_body = json_decode( $this->_obj_write_string, True );
			return array( $return_code, $this->response_reason, $json_body );
		}
		$this->error_str = "Unexpected HTTP response code: $return_code";
		return array( 0, $this->error_str, array( ) );
	}

	# HEAD /v1/Account/Container

	#
    public function head_container( $container_name ) {
		$container_name = (string)$container_name;
		if ( $container_name === "" ) {
			$this->error_str = "Container name not set.";
			return False;
		}

		$conn_type = "HEAD";

		$url_path = $this->_make_path( "STORAGE", $container_name );
		$return_code = $this->_send_request( $conn_type, $url_path );

		if ( !$return_code ) {
			$this->error_str .= ": Failed to obtain valid HTTP response.";
			return array( 0, $this->error_str, 0, 0 );
		}
		if ( $return_code == 404 ) {
			return array( $return_code, "Container not found.", 0, 0 );
		}
		if ( $return_code == 204 || $return_code == 200 ) {
			return array( $return_code, $this->response_reason,
				$this->_container_object_count, $this->_container_bytes_used );
		}
		return array( $return_code, $this->response_reason, 0, 0 );
	}

	# GET /v1/Account/Container/Object

	#
    public function get_object_to_string( CF_Object $obj, $hdrs = array( ) ) {
		$conn_type = "GET_CALL";

		$url_path = $this->_make_path( "STORAGE", $obj->container->name, $obj->name );
		$this->_write_callback_type = "OBJECT_STRING";
		$return_code = $this->_send_request( $conn_type, $url_path, $hdrs );

		if ( !$return_code ) {
			$this->error_str .= ": Failed to obtain valid HTTP response.";
			return array( $return_code, $this->error_str, NULL );
		}
		if ( $return_code == 404 ) {
			$this->error_str = "Object not found.";
			return array( $return_code, $this->error_str, NULL );
		}
		if ( ($return_code < 200) || ($return_code > 299
			&& $return_code != 412 && $return_code != 304) )
		{
			$this->error_str = "Unexpected HTTP return code: $return_code";
			return array( $return_code, $this->error_str, NULL );
		}
		return array( $return_code, $this->response_reason, $this->_obj_write_string );
	}

	# GET /v1/Account/Container/Object

	#
    public function get_object_to_stream(
		$async, CF_Object $obj, &$resource = NULL, $hdrs = array( )
	) {
		if ( !is_resource( $resource ) ) {
			throw new SyntaxException( "Resource argument not a valid PHP resource." );
		}

		$conn_type = "GET_CALL";
		$url_path = $this->_make_path( "STORAGE", $obj->container->name, $obj->name );

		if ( $async === 'async' ) {
			$cf_http = $this->forkClient(); // separate TCP connection & response holder
			$cf_http->_obj_write_resource = $resource;
			$cf_http->_write_callback_type = "OBJECT_STREAM";
			return new CF_Async_Op( $cf_http, 'get_object_to_stream_RETURN',
				$cf_http->_send_request_HANDLE( $conn_type, $url_path, $hdrs )
			);
		} else {
			$this->_obj_write_resource = $resource;
			$this->_write_callback_type = "OBJECT_STREAM";
			$return_code = $this->_send_request( $conn_type, $url_path, $hdrs );
			return $this->get_object_to_stream_RETURN( $return_code );
		}
	}

	/*private*/ function get_object_to_stream_RETURN( $return_code ) {
		if ( !$return_code ) {
			$this->error_str .= ": Failed to obtain valid HTTP response.";
			return array( $return_code, $this->error_str );
		}
		if ( $return_code == 404 ) {
			$this->error_str = "Object not found.";
			return array( $return_code, $this->error_str );
		}
		if ( ($return_code < 200) || ($return_code > 299
			&& $return_code != 412 && $return_code != 304) )
		{
			$this->error_str = "Unexpected HTTP return code: $return_code";
			return array( $return_code, $this->error_str );
		}
		return array( $return_code, $this->response_reason );
	}

	# PUT /v1/Account/Container/Object

	#
    public function put_object( $async, CF_Object $obj, &$fp ) {
		if ( !is_resource( $fp ) ) {
			throw new SyntaxException( "File pointer argument is not a valid resource." );
		}

		$conn_type = "PUT_OBJ";
		$url_path = $this->_make_path( "STORAGE", $obj->container->name, $obj->name );

		$hdrs = $this->_headers( $obj );

		$etag = $obj->getETag();
		if ( isset( $etag ) ) {
			$hdrs[] = "ETag: " . $etag;
		}
		if ( !$obj->content_type ) {
			$hdrs[] = "Content-Type: application/octet-stream";
		} else {
			$hdrs[] = "Content-Type: " . $obj->content_type;
		}

		if ( $async === 'async' ) {
			$cf_http = $this->forkClient(); // separate TCP connection & response holder
		} else {
			$cf_http = $this;
		}

		$cf_http->_init( $conn_type );
		curl_setopt( $cf_http->connections[$conn_type], CURLOPT_INFILE, $fp );
		if ( $obj->content_length === null ) {
			# We don''t know the Content-Length, so assumed "chunked" PUT
            curl_setopt( $cf_http->connections[$conn_type], CURLOPT_UPLOAD, True );
			$hdrs[] = 'Transfer-Encoding: chunked';
		} else {
			# We know the Content-Length, so use regular transfer
            curl_setopt( $cf_http->connections[$conn_type], CURLOPT_INFILESIZE, $obj->content_length );
		}

		if ( $async === 'async' ) {
			return new CF_Async_Op( $cf_http, 'put_object_RETURN',
				$cf_http->_send_request_HANDLE( $conn_type, $url_path, $hdrs )
			);
		} else {
			$return_code = $cf_http->_send_request( $conn_type, $url_path, $hdrs );
			return $cf_http->put_object_RETURN( $return_code );
		}
	}

    /*private*/ function put_object_RETURN( $return_code ) {
		if ( !$return_code ) {
			$this->error_str .= ": Failed to obtain valid HTTP response.";
			return array( 0, $this->error_str, NULL );
		}
		if ( $return_code == 412 ) {
			$this->error_str = "Missing Content-Type header";
			return array( $return_code, $this->error_str, NULL );
		}
		if ( $return_code == 422 ) {
			$this->error_str = "Derived and computed checksums do not match.";
			return array( $return_code, $this->error_str, NULL );
		}
		if ( $return_code != 201 ) {
			$this->error_str = "Unexpected HTTP return code: $return_code";
			return array( $return_code, $this->error_str, NULL );
		}
		return array( $return_code, $this->response_reason, $this->_obj_etag );
	}

	# POST /v1/Account/Container/Object

	#
    public function update_object( CF_Object $obj ) {
		# TODO: The is_array check isn't in sync with the error message
		if ( !$obj->manifest && !(is_array( $obj->metadata ) || is_array( $obj->headers )) ) {
			$this->error_str = "Metadata and headers arrays are empty.";
			return 0;
		}

		$url_path = $this->_make_path( "STORAGE", $obj->container->name, $obj->name );

		$hdrs = $this->_headers( $obj );
		$return_code = $this->_send_request( "DEL_POST", $url_path, $hdrs, "POST" );
		switch ( $return_code ) {
			case 202:
				break;
			case 0:
				$this->error_str .= ": Failed to obtain valid HTTP response.";
				$return_code = 0;
				break;
			case 404:
				$this->error_str = "Account, Container, or Object not found.";
				break;
			default:
				$this->error_str = "Unexpected HTTP return code: $return_code";
				break;
		}
		return $return_code;
	}

	# HEAD /v1/Account/Container/Object

	#
    public function head_object( CF_Object $obj, $hdrs = array( ) ) {
		$conn_type = "HEAD";

		$url_path = $this->_make_path( "STORAGE", $obj->container->name, $obj->name );
		$return_code = $this->_send_request( $conn_type, $url_path, $hdrs );

		if ( !$return_code ) {
			$this->error_str .= ": Failed to obtain valid HTTP response.";
			return array( 0, $this->error_str . " " . $this->response_reason,
				NULL, NULL, NULL, NULL, array( ), NULL, array( ) );
		}

		if ( $return_code == 404 ) {
			return array( $return_code, $this->response_reason,
				NULL, NULL, NULL, NULL, array( ), NULL, array( ) );
		}
		if ( $return_code == 204 || $return_code == 200 ) {
			return array( $return_code, $this->response_reason,
				$this->_obj_etag,
				$this->_obj_last_modified,
				$this->_obj_content_type,
				$this->_obj_content_length,
				$this->_obj_metadata,
				$this->_obj_manifest,
				$this->_obj_headers );
		}
		$this->error_str = "Unexpected HTTP return code: $return_code";
		return array( $return_code, $this->error_str . " " . $this->response_reason,
			NULL, NULL, NULL, NULL, array( ), NULL, array( ) );
	}

	# COPY /v1/Account/Container/Object

	#
    public function copy_object(
		$async, $src_obj_name, $dest_obj_name, $container_name_source, $container_name_target,
		$metadata = NULL, $headers = NULL
	) {
		$src_obj_name = (string)$src_obj_name;
		if ( $src_obj_name === "" ) {
			$this->error_str = "Object name not set.";
			return 0;
		}

		$container_name_source = (string)$container_name_source;
		if ( $container_name_source === "" ) {
			$this->error_str = "Container name source not set.";
			return 0;
		}

		$container_name_target = (string)$container_name_target;
		if ( $container_name_target === "" ) {
			$this->error_str = "Container name target not set.";
			return 0;
		}

		$conn_type = "COPY";

		$url_path = $this->_make_path( "STORAGE", $container_name_source, $src_obj_name );
		$destination = $container_name_target . "/" . $dest_obj_name;

		$hdrs = self::_process_headers( $metadata, $headers );
		$hdrs[DESTINATION] = str_replace( "%2F", "/", rawurlencode( $destination ) );

		if ( $async === 'async' ) {
			$cf_http = $this->forkClient(); // separate TCP connection & response holder
			return new CF_Async_Op( $cf_http, 'copy_object_RETURN',
				$cf_http->_send_request_HANDLE( $conn_type, $url_path, $hdrs, "COPY" )
			);
		} else {
			$return_code = $this->_send_request( $conn_type, $url_path, $hdrs, "COPY" );
			return $this->copy_object_RETURN( $return_code );
		}
	}

	/*private*/ function copy_object_RETURN( $return_code ) {
		switch ( $return_code ) {
			case 201:
				break;
			case 0:
				$this->error_str .= ": Failed to obtain valid HTTP response.";
				$return_code = 0;
				break;
			case 404:
				$this->error_str = "Specified container/object did not exist.";
				break;
			default:
				$this->error_str = "Unexpected HTTP return code: $return_code.";
		}
		return array( $return_code, $this->response_reason );
	}

	# DELETE /v1/Account/Container/Object

	#
    public function delete_object( $async, $container_name, $object_name ) {
		$container_name = (string)$container_name;
		if ( $container_name === "" ) {
			$this->error_str = "Container name not set.";
			return 0;
		}

		$object_name = (string)$object_name;
		if ( $object_name === "" ) {
			$this->error_str = "Object name not set.";
			return 0;
		}

		$url_path = $this->_make_path( "STORAGE", $container_name, $object_name );

		if ( $async === 'async' ) {
			$cf_http = $this->forkClient(); // separate TCP connection & response holder
			return new CF_Async_Op( $cf_http, 'delete_object_RETURN',
				$cf_http->_send_request_HANDLE( "DEL_POST", $url_path, NULL, "DELETE" )
			);
		} else {
			$return_code = $this->_send_request( "DEL_POST", $url_path, NULL, "DELETE" );
			return $this->delete_object_RETURN( $return_code );
		}
	}

    /*private*/ function delete_object_RETURN( $return_code ) {
		switch ( $return_code ) {
			case 204:
				break;
			case 0:
				$this->error_str .= ": Failed to obtain valid HTTP response.";
				$return_code = 0;
				break;
			case 404:
				$this->error_str = "Specified container did not exist to delete.";
				break;
			default:
				$this->error_str = "Unexpected HTTP return code: $return_code.";
		}
		return array( $return_code, $this->response_reason );
	}

	public function get_error() {
		return $this->error_str;
	}

	public function setDebug( $bool ) {
		$this->dbug = $bool;
		foreach ( $this->connections as $k => $v ) {
			if ( !is_null( $v ) ) {
				curl_setopt( $this->connections[$k], CURLOPT_VERBOSE, $this->dbug );
			}
		}
	}

	public function getCDNMUrl() {
		return $this->cdnm_url;
	}

	public function getStorageUrl() {
		return $this->storage_url;
	}

	public function getAuthToken() {
		return $this->auth_token;
	}

	public function setCFAuth( $cfs_auth, $servicenet = False ) {
		if ( $servicenet ) {
			$this->storage_url = "https://snet-" . substr( $cfs_auth->storage_url, 8 );
		} else {
			$this->storage_url = $cfs_auth->storage_url;
		}
		$this->auth_token = $cfs_auth->auth_token;
		$this->cdnm_url = $cfs_auth->cdnm_url;
	}

	/**
	 * Create a new CF_Http instance with the same authentication
	 * @see CF_Http::setCFAuth()
	 */
	private function forkClient() {
		$cf_http = new self( CF_Constants::DEFAULT_CF_API_VERSION );
		$cf_http->storage_url = $this->storage_url;
		$cf_http->auth_token = $this->auth_token;
		$cf_http->cdnm_url = $this->cdnm_url;
		return $cf_http;
	}

	function setReadProgressFunc( $func_name ) {
		$this->_user_read_progress_callback_func = $func_name;
	}

	function setWriteProgressFunc( $func_name ) {
		$this->_user_write_progress_callback_func = $func_name;
	}

	/*private*/ function _header_cb( $ch, $header ) {
		$header_len = strlen( $header );

		if ( preg_match( "/^(HTTP\/1\.[01]) (\d{3}) (.*)/", $header, $matches ) ) {
			$this->response_status = $matches[2];
			$this->response_reason = $matches[3];
			return $header_len;
		}

		if ( strpos( $header, ":" ) === False )
			return $header_len;
		list($name, $value) = explode( ":", $header, 2 );
		$value = trim( $value );

		switch ( strtolower( $name ) ) {
			case strtolower( CDN_ENABLED ):
				$this->_cdn_enabled = strtolower( $value ) == "true";
				break;
			case strtolower( CDN_URI ):
				$this->_cdn_uri = $value;
				break;
			case strtolower( CDN_SSL_URI ):
				$this->_cdn_ssl_uri = $value;
				break;
			case strtolower( CDN_STREAMING_URI ):
				$this->_cdn_streaming_uri = $value;
				break;
			case strtolower( CDN_TTL ):
				$this->_cdn_ttl = $value;
				break;
			case strtolower( MANIFEST_HEADER ):
				$this->_obj_manifest = $value;
				break;
			case strtolower( CDN_LOG_RETENTION ):
				$this->_cdn_log_retention = strtolower( $value ) == "true";
				break;
			case strtolower( CDN_ACL_USER_AGENT ):
				$this->_cdn_acl_user_agent = $value;
				break;
			case strtolower( CDN_ACL_REFERRER ):
				$this->_cdn_acl_referrer = $value;
				break;
			case strtolower( ACCOUNT_CONTAINER_COUNT ):
				$this->_account_container_count = (float) $value + 0;
				break;
			case strtolower( ACCOUNT_BYTES_USED ):
				$this->_account_bytes_used = (float) $value + 0;
				break;
			case strtolower( CONTAINER_OBJ_COUNT ):
				$this->_container_object_count = (float) $value + 0;
				break;
			case strtolower( CONTAINER_BYTES_USED ):
				$this->_container_bytes_used = (float) $value + 0;
				break;
			case strtolower( ETAG_HEADER ):
				$this->_obj_etag = $value;
				break;
			case strtolower( LAST_MODIFIED_HEADER ):
				$this->_obj_last_modified = $value;
				break;
			case strtolower( CONTENT_TYPE_HEADER ):
				$this->_obj_content_type = $value;
				break;
			case strtolower( CONTENT_LENGTH_HEADER ):
				$this->_obj_content_length = (float) $value + 0;
				break;
			case strtolower( ORIGIN_HEADER ):
				$this->_obj_headers[ORIGIN_HEADER] = $value;
				break;
			default:
				if ( strncasecmp( $name,
					METADATA_HEADER_PREFIX, strlen( METADATA_HEADER_PREFIX ) ) == 0
				) {
					$name = substr( $name, strlen( METADATA_HEADER_PREFIX ) );
					$this->_obj_metadata[$name] = $value;
				} elseif (
					strncasecmp( $name,
						CONTENT_HEADER_PREFIX, strlen( CONTENT_HEADER_PREFIX ) ) == 0 ||
					strncasecmp( $name,
						X_CONTENT_HEADER_PREFIX, strlen( X_CONTENT_HEADER_PREFIX ) ) == 0 ||
					strncasecmp( $name,
						ACCESS_CONTROL_HEADER_PREFIX, strlen( ACCESS_CONTROL_HEADER_PREFIX ) ) == 0
				) {
					$this->_obj_headers[$name] = $value;
				}
		}
		return $header_len;
	}

	/*private*/ function _read_cb( $ch, $fd, $length ) {
		$data = fread( $fd, $length );
		$len = strlen( $data );
		if ( isset( $this->_user_write_progress_callback_func ) ) {
			call_user_func( $this->_user_write_progress_callback_func, $len );
		}
		return $data;
	}

	/*private*/ function _write_cb( $ch, $data ) {
		$dlen = strlen( $data );
		switch ( $this->_write_callback_type ) {
			case "TEXT_LIST":
				$this->_return_list = $this->_return_list . $data;
				//= explode("\n",$data); # keep tab,space
				//his->_text_list[] = rtrim($data,"\n\r\x0B"); # keep tab,space
				break;
			case "OBJECT_STREAM":
				fwrite( $this->_obj_write_resource, $data, $dlen );
				break;
			case "OBJECT_STRING":
				$this->_obj_write_string .= $data;
				break;
		}
		if ( isset( $this->_user_read_progress_callback_func ) ) {
			call_user_func( $this->_user_read_progress_callback_func, $dlen );
		}
		return $dlen;
	}

	/*private*/ function _auth_hdr_cb( $ch, $header ) {
		preg_match( "/^HTTP\/1\.[01] (\d{3}) (.*)/", $header, $matches );
		if ( isset( $matches[1] ) ) {
			$this->response_status = $matches[1];
		}
		if ( isset( $matches[2] ) ) {
			$this->response_reason = $matches[2];
		}
		return strlen( $header );
	}

	private function _make_headers( $hdrs = NULL ) {
		$new_headers = array( );
		$has_stoken = False;
		$has_uagent = False;
		if ( is_array( $hdrs ) ) {
			foreach ( $hdrs as $h => $v ) {
				if ( is_int( $h ) ) {
					list($h, $v) = explode( ":", $v, 2 );
				}

				if ( strncasecmp( $h, AUTH_TOKEN, strlen( AUTH_TOKEN ) ) === 0 ) {
					$has_stoken = True;
				}
				if ( strncasecmp( $h, USER_AGENT_HEADER, strlen( USER_AGENT_HEADER ) ) === 0 ) {
					$has_uagent = True;
				}
				$new_headers[] = $h . ": " . trim( $v );
			}
		}
		if ( !$has_stoken ) {
			$new_headers[] = AUTH_TOKEN . ": " . $this->auth_token;
		}
		if ( !$has_uagent ) {
			$new_headers[] = USER_AGENT_HEADER . ": " . USER_AGENT;
		}
		return $new_headers;
	}

	private function _init( $conn_type, $force_new = False ) {
		if ( !array_key_exists( $conn_type, $this->connections ) ) {
			$this->error_str = "Invalid CURL_XXX connection type";
			return False;
		}

		if ( is_null( $this->connections[$conn_type] ) || $force_new ) {
			$ch = curl_init();
		} else {
			return;
		}

		if ( $this->dbug ) {
			curl_setopt( $ch, CURLOPT_VERBOSE, 1 );
		}

		if ( !is_null( $this->cabundle_path ) ) {
			curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, True );
			curl_setopt( $ch, CURLOPT_CAINFO, $this->cabundle_path );
		}
		curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, True );
		curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, 1 );
		curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 10 );
		curl_setopt( $ch, CURLOPT_TIMEOUT, 300 );
		curl_setopt( $ch, CURLOPT_MAXREDIRS, 4 );
		curl_setopt( $ch, CURLOPT_HEADER, 0 );
		curl_setopt( $ch, CURLOPT_HEADERFUNCTION, array( &$this, '_header_cb' ) );

		if ( $conn_type == "GET_CALL" ) {
			curl_setopt( $ch, CURLOPT_WRITEFUNCTION, array( &$this, '_write_cb' ) );
		}

		if ( $conn_type == "PUT_OBJ" ) {
			curl_setopt( $ch, CURLOPT_PUT, 1 );
			curl_setopt( $ch, CURLOPT_READFUNCTION, array( &$this, '_read_cb' ) );
			curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
		}
		if ( $conn_type == "HEAD" ) {
			curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, "HEAD" );
			curl_setopt( $ch, CURLOPT_NOBODY, 1 );
		}
		if ( $conn_type == "PUT_CONT" ) {
			curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, "PUT" );
			curl_setopt( $ch, CURLOPT_INFILESIZE, 0 );
			curl_setopt( $ch, CURLOPT_NOBODY, 1 );
		}
		if ( $conn_type == "DEL_POST" ) {
			curl_setopt( $ch, CURLOPT_NOBODY, 1 );
		}
		if ( $conn_type == "COPY" ) {
			curl_setopt( $ch, CURLOPT_NOBODY, 1 );
		}
		$this->connections[$conn_type] = $ch;
		return;
	}

	private function _reset_callback_vars() {
		$this->_text_list = array( );
		$this->_return_list = NULL;
		$this->_account_container_count = 0;
		$this->_account_bytes_used = 0;
		$this->_container_object_count = 0;
		$this->_container_bytes_used = 0;
		$this->_obj_etag = NULL;
		$this->_obj_last_modified = NULL;
		$this->_obj_content_type = NULL;
		$this->_obj_content_length = NULL;
		$this->_obj_metadata = array( );
		$this->_obj_manifest = NULL;
		$this->_obj_headers = array( );
		$this->_obj_write_string = "";
		$this->_cdn_streaming_uri = NULL;
		$this->_cdn_enabled = NULL;
		$this->_cdn_ssl_uri = NULL;
		$this->_cdn_uri = NULL;
		$this->_cdn_ttl = NULL;
		$this->response_status = 0;
		$this->response_reason = "";
	}

	private function _make_path( $t = "STORAGE", $c = NULL, $o = NULL ) {
		$path = array( );
		switch ( $t ) {
			case "STORAGE":
				$path[] = $this->storage_url;
				break;
			case "CDN":
				$path[] = $this->cdnm_url;
				break;
		}
		$c = (string)$c;
		if ( $c !== "" ) {
			$path[] = rawurlencode( $c );
		}
		if ( $o ) {
			# mimic Python''s urllib.quote() feature of a "safe" '/' character
            $path[] = str_replace( "%2F", "/", rawurlencode( $o ) );
		}
		return implode( "/", $path );
	}

	private function _headers( CF_Object $obj ) {
		$hdrs = self::_process_headers( $obj->metadata, $obj->headers );
		if ( $obj->manifest )
			$hdrs[MANIFEST_HEADER] = $obj->manifest;

		return $hdrs;
	}

	private function _process_headers( $metadata = null, $headers = null ) {
		$rules = array(
			array(
				'prefix' => METADATA_HEADER_PREFIX,
			),
			array(
				'prefix' => '',
				'filter' => array( # key order is important, first match decides
					X_CONTENT_HEADER_PREFIX => true,
					CONTENT_TYPE_HEADER => false,
					CONTENT_LENGTH_HEADER => false,
					CONTENT_HEADER_PREFIX => true,
					ACCESS_CONTROL_HEADER_PREFIX => true,
					ORIGIN_HEADER => true,
				),
			),
		);

		$hdrs = array( );
		$argc = func_num_args();
		$argv = func_get_args();
		for ( $argi = 0; $argi < $argc; $argi++ ) {
			if ( !is_array( $argv[$argi] ) ) {
				continue;
			}
			$rule = $rules[$argi];
			foreach ( $argv[$argi] as $k => $v ) {
				$k = trim( $k );
				$v = trim( $v );
				if ( strpos( $k, ":" ) !== False ) {
					throw new SyntaxException( "Header names cannot contain a ':' character." );
				}

				if ( array_key_exists( 'filter', $rule ) ) {
					$result = null;
					foreach ( $rule['filter'] as $p => $f ) {
						if ( strncasecmp( $k, $p, strlen( $p ) ) == 0 ) {
							$result = $f;
							break;
						}
					}
					if ( !$result ) {
						throw new SyntaxException(
							sprintf( "Header name %s is not allowed", $k ) );
					}
				}

				$k = $rule['prefix'] . $k;
				if ( strlen( $k ) > MAX_HEADER_NAME_LEN || strlen( $v ) > MAX_HEADER_VALUE_LEN ) {
					throw new SyntaxException( sprintf(
						"Header %s exceeds maximum length: %d/%d", $k, strlen( $k ), strlen( $v )
					) );
				}
				$hdrs[$k] = $v;
			}
		}

		return $hdrs;
	}

	private function _send_request(
		$conn_type, $url_path, $hdrs = NULL, $method = "GET", $force_new = False
	) {
		$handle = $this->_send_request_HANDLE( $conn_type, $url_path, $hdrs, $method, $force_new );
		return $this->_send_request_EXEC( $handle );
	}

	private function _send_request_HANDLE(
		$conn_type, $url_path, $hdrs = NULL, $method = "GET", $force_new = False
	) {
		$this->_init( $conn_type, $force_new );
		$this->_reset_callback_vars();
		$headers = $this->_make_headers( $hdrs );

		if ( gettype( $this->connections[$conn_type] ) == "unknown type" ) {
			throw new ConnectionNotOpenException( "Connection is not open." );
		}

		switch ( $method ) {
			case "COPY":
				curl_setopt( $this->connections[$conn_type], CURLOPT_CUSTOMREQUEST, "COPY" );
				break;
			case "DELETE":
				curl_setopt( $this->connections[$conn_type], CURLOPT_CUSTOMREQUEST, "DELETE" );
				break;
			case "POST":
				curl_setopt( $this->connections[$conn_type], CURLOPT_CUSTOMREQUEST, "POST" );
			default:
				break;
		}

		curl_setopt( $this->connections[$conn_type], CURLOPT_HTTPHEADER, $headers );
		curl_setopt( $this->connections[$conn_type], CURLOPT_URL, $url_path );

		return $this->connections[$conn_type];
	}

	private function _send_request_EXEC( $handle ) {
		if ( curl_exec( $handle ) === false ) {
			$this->error_str = "(curl error: " . curl_errno( $handle ) . ") ";
			$this->error_str .= curl_error( $handle );
			return False;
		}
		return $this->_send_request_RETURN( $handle );
	}

	private function _send_request_RETURN( $handle ) {
		if ( curl_errno( $handle ) !== 0 ) {
			$this->error_str = "(curl error: " . curl_errno( $handle ) . ") ";
			$this->error_str .= curl_error( $handle );
			return False;
		}
		return curl_getinfo( $handle, CURLINFO_HTTP_CODE );
	}

	/**
	 * Used by CF_Async_Op
	 * @return integer HTTP status
	 */
	public function readAsyncResponse( $handle ) {
		return $this->_send_request_RETURN( $handle );
	}

	public function close() {
		foreach ( $this->connections as $i => $cnx ) {
			if ( isset( $cnx ) ) {
				curl_close( $cnx );
				$this->connections[$i] = NULL;
			}
		}
	}

	private function create_array() {
		$this->_text_list = explode( "\n", rtrim( $this->_return_list, "\n\x0B" ) );
		return True;
	}
}

/**
 * Helper class for handling an async HTTP operation.
 *
 * Operations can consist of sequential "steps", where later steps are
 * only attempted if previous ones succeed. Each step is an HTTP request.
 * For example, moving a file consist of a PUT request and a DELETE request.
 */
class CF_Async_Op {
	const STATE_NEW = 0;
	const STATE_QUEUED = 1;
	const STATE_STARTED = 2;
	const STATE_FINISHED = 3;

	/** @var Array */
	private $steps = array() ; // (CF_Http object, PHP callback, curl handle) tuples
	/** @var Array */
	private $callback; // PHP callback
	/** @var Array */
	private $callbackInfo = array();

	public $state = self::STATE_NEW; // integer
	public $failed = false; // boolean

	/** @var mixed */
	private $lastResponse; // last result of HTTP callback

	/**
	 * Note: $http_callback callback must return an HTTP status
	 *
	 * @param $cf_http CF_Http
	 * @param $http_callback String Method name of the CF_Http object
	 * @param $handle resource cURL handle
	 * @throws SyntaxException
	 */
	public function __construct( CF_Http $cf_http, $http_callback, $handle ) {
		$http_callback = array( $cf_http, $http_callback );
		if ( !is_callable( $http_callback ) ) {
			throw new SyntaxException( "Invalid HTTP callback" );
		} elseif ( !is_resource( $handle ) ) {
			throw new SyntaxException( "Invalid cURL handle" );
		}
		$this->steps[] = array( // first step
			'http' => $cf_http, 'http_callback' => $http_callback, 'handle' => $handle
		);
	}

	/**
	 * Set the final callback and the array of values to pass to this callback
	 *
	 * @param $callback Array Final PHP callback
	 * @param $info Array
	 * @return CF_Async_Op This object
	 */
	public function setCallback( Closure $callback, array $info ) {
		if ( !is_callable( $callback ) ) {
			throw new SyntaxException( 'Invalid callback given' );
		}
		$this->callback     = $callback;
		$this->callbackInfo = $info;
		return $this;
	}

	/**
	 * Append the steps of another CF_Async_Op object to the steps
	 * of this one and set the callback to that of the given object.
	 * Later steps will not be attempted if prior ones failed.
	 *
	 * @param $cfOp CF_Async_Op
	 * @return CF_Async_Op This object
	 * @throws SyntaxException
	 */
	public function combine( CF_Async_Op $cfOp ) {
		if ( $this->state !== self::STATE_NEW ) {
			throw new SyntaxException( "curl handle already queued" );
		}
		foreach ( $cfOp->steps as $step ) {
			$this->steps[] = $step;
		}
		$this->callback     = $cfOp->callback;
		$this->callbackInfo = $cfOp->callbackInfo;
		return $this;
	}

	/**
	 * @return integer
	 */
	public function countSteps() {
		return count( $this->steps );
	}

	/**
	 * Get the cURL handle for this step
	 * @param $i integer
	 * @return resource|null Returns null if this step does not exist
	 */
	public function getStepHandle( $i ) {
		return isset( $this->steps[$i] ) ? $this->steps[$i]['handle'] : null;
	}

	/**
	 * Runs the callback for a step and stores its return value
	 * @param $i integer
	 * @return void
	 * @throws SyntaxException
	 */
	public function setStepResult( $i ) {
		if ( $this->state !== self::STATE_STARTED ) {
			throw new SyntaxException( "curl handle exec not called" );
		} elseif ( $this->failed ) {
			throw new SyntaxException( "curl handle exec already failed" );
		} elseif ( !isset( $this->steps[$i] ) ) {
			throw new SyntaxException( "No such HTTP request step" );
		}
		$cf_http = $this->steps[$i]['http'];
		// Interpret the HTTP response and set the error flags...
		$status = $cf_http->readAsyncResponse( $this->steps[$i]['handle'] ); // HTTP status
		$response = call_user_func( $this->steps[$i]['http_callback'], $status );
		// Check if any errors occured...
		if ( (string)$cf_http->get_error() !== '' ) { // '' or null
			$this->failed = true; // abort any remaning steps
		}
		$cf_http->close(); // close cURL handles
		// Update last response so far...the final one will be passed to the callback
		$this->lastResponse = $response;
	}

	/**
	 * Return the resulting value of the final callback for the last step.
	 * The first argument to final the callback is the response from the HTTP
	 * callback function. The second argument is an array set via setInfo().
	 * The callback function may throw various types of cloudfiles exceptions.
	 *
	 * @return mixed
	 * @throws SyntaxException
	 * @throws CloudFilesException
	 */
	public function getLastResponse() {
		if ( $this->state !== self::STATE_FINISHED ) {
			throw new SyntaxException( "curl handle exec not called on all steps" );
		} elseif ( !$this->callback ) {
			throw new SyntaxException( 'No callback was given' );
		}
		return call_user_func( $this->callback, $this->lastResponse, $this->callbackInfo );
	}
}

/**
 * Helper class for handling multiple async HTTP operations.
 * This does the first stage for each request in parallel, then the second
 * in parallel, and so on, until all stages of all requests are finished.
 * For multi-stage requests, only the result for last stage is returned.
 */
class CF_Async_Op_Batch {
	/** @var Array */
	private $requests; // lists of CF_Async_Op objects
	private $steps = 0; // integer

	/**
	 * @param $requests Array of CF_Async_Op objects
	 */
	public function __construct( array $requests ) {
		foreach ( $requests as $request ) {
			$request->state = CF_Async_Op::STATE_QUEUED;
			$this->steps = max( $this->steps, $request->countSteps() );
		}
		$this->requests = $requests;
	}

	/**
	 * Perform all of the HTTP requests for these operations.
	 * This returns the CF_Async_Op objects, and getLastResponse() can be called on them.
	 * Exceptions may thrown when that function is called if the operation failed.
	 * @return Array The list of CF_Async_Op objects (keys and order preserved)
	 */
	public function execute() {
		if ( !count( $this->requests ) ) {
			return $this->requests; // nothing to do
		}
		$multiHandle = CF_Curl_Multi::singleton()->getHandle();
		for ( $stage=0; $stage < $this->steps; $stage++ ) {
			// Add all of the required cURL handles...
			foreach ( $this->requests as $request ) {
				$handle = $request->getStepHandle( $stage );
				if ( $handle && !$request->failed ) { // has step
					$request->state = CF_Async_Op::STATE_STARTED;
					curl_multi_add_handle( $multiHandle, $handle );
				}
			}
			// Execute the cURL handles concurrently...
			$active = null; // handles still being processed
			if ( substr( php_uname(), 0, 7 ) == 'Windows' ) {
				do { // avoid curl_multi_select() per PHP bugs 61141 and 61240
					$mrc = curl_multi_exec( $multiHandle, $active );
					if ( $mrc != CURLM_CALL_MULTI_PERFORM ) {
						usleep( 100 ); // .1ms
					}
				} while ( $mrc == CURLM_CALL_MULTI_PERFORM || ( $active && $mrc == CURLM_OK ) );
			} else {
				do { // start all the requests...
					$mrc = curl_multi_exec( $multiHandle, $active );
				} while ( $mrc == CURLM_CALL_MULTI_PERFORM );
				while ( $active && $mrc == CURLM_OK ) {
					if ( curl_multi_select( $multiHandle, 10 ) != -1 ) {
						do { // keep working on this request...
							$mrc = curl_multi_exec( $multiHandle, $active );
						} while ( $mrc == CURLM_CALL_MULTI_PERFORM );
					}
				}
			}
			// Remove all of the added cURL handles and check for errors...
			foreach ( $this->requests as $request ) {
				$handle = $request->getStepHandle( $stage );
				if ( $handle && !$request->failed ) { // has step
					curl_multi_remove_handle( $multiHandle, $handle );
					$request->setStepResult( $stage ); // set error string/bit
				}
			}
		}
		foreach ( $this->requests as $request ) {
			$request->state = CF_Async_Op::STATE_FINISHED;
		}
		return $this->requests;
	}
}

class CF_Curl_Multi {
	/** @var resource */
	private $handle; // curl_multi handle

	private function __construct() {
		$this->handle = curl_multi_init();
	}

	/**
	 * @return CF_Curl_Multi
	 */
	public static function singleton() {
		static $instance = null;
		if ( !$instance ) {
			$instance = new self();
		}
		return $instance;
	}

	/**
	 * @return resource Handle from curl_multi_init()
	 */
	public function getHandle() {
		return $this->handle;
	}

	function __destruct() {
		curl_multi_close( $this->handle );
	}
}
