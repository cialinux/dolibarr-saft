<?php
http_response_code(410);
header('Content-Type: text/plain; charset=UTF-8');
print "Legacy endpoint disabled. Use import/index.php.\n";
exit;