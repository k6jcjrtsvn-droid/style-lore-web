<?php
/**
 * Answers CORS preflight (OPTIONS) requests for every /api/* path.
 *
 * Most API rewrites in .htaccess send any method to the matching script,
 * whose require of includes/helpers.php answers the preflight. A few
 * rewrites are method-conditional (DELETE/PUT on /api/posts/:id), so an
 * OPTIONS request to those paths would otherwise 404 and the native
 * apps' cross-origin DELETE/PUT would never be sent. .htaccess routes all
 * OPTIONS /api/* requests here instead.
 */
require_once __DIR__ . '/../includes/helpers.php';

// helpers.php already exited with 204 for an allowed origin; anything
// that reaches this line is a preflight from an origin we don't serve.
http_response_code(204);
