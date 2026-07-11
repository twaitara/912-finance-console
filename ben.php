<?php
/* ben.php — kept only as a friendly shortcut. The Ben Portal now lives inside
   index.php (?portal=ben) so it deploys with the one file that always ships.
   Redirect any old bookmarks there. */
header('Location: index.php?portal=ben', true, 301);
exit;
