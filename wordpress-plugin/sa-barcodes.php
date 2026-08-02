<?php
/*
Plugin Name: SA Barcodes Store
Description: מאגר ברקודים לסורק "סופר זול אסולין" - שמירה במסד הנתונים דרך REST (ללא קבצים, עובר חומת אש).
Version: 1.0
*/
if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function () {
  register_rest_route('sa/v1', '/list', array(
    array('methods' => 'GET',                'callback' => 'sa_bc_get',  'permission_callback' => '__return_true'),
    array('methods' => array('POST','PUT'),  'callback' => 'sa_bc_post', 'permission_callback' => '__return_true'),
  ));
});

function sa_bc_optkey($req){
  $l = preg_replace('/[^a-z0-9]/', '', strtolower((string)$req->get_param('list')));
  if ($l === '') $l = 'scanned';
  return 'sa_bc_' . $l;
}

function sa_bc_token($req){
  $t = (string)$req->get_param('t');
  if ($t === '') $t = (string)$req->get_header('x_sa_token');
  return $t;
}

function sa_bc_get($req){
  $data = get_option(sa_bc_optkey($req), array());
  if (!is_array($data)) $data = array();
  return new WP_REST_Response((object)$data, 200);
}

function sa_bc_post($req){
  $TOKEN = 'CHANGE_ME_TOKEN';
  $ADMIN = 'CHANGE_ME_ADMIN_KEY';

  if (sa_bc_token($req) !== $TOKEN)
    return new WP_REST_Response(array('error' => 'forbidden'), 403);

  $incoming = json_decode($req->get_body(), true);
  if (!is_array($incoming))
    return new WP_REST_Response(array('error' => 'bad json'), 400);

  $key     = sa_bc_optkey($req);
  $replace = ((string)$req->get_param('mode') === 'replace');
  if ($replace && (string)$req->get_param('admin') !== $ADMIN)
    return new WP_REST_Response(array('error' => 'admin key required'), 403);

  if ($replace) {
    $data = $incoming;
  } else {
    $data = get_option($key, array());
    if (!is_array($data)) $data = array();
    foreach ($incoming as $k => $v) { $data[(string)$k] = $v; } // מיזוג איחוד - לא מאבד ולא כופל
  }

  update_option($key, $data, false); // autoload=false
  return new WP_REST_Response((object)$data, 200);
}
