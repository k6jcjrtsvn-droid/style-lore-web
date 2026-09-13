<?php
// Retired diagnostic. Self-removes and answers 404 if it is ever deployed again.
@unlink(__FILE__);
http_response_code(404);
