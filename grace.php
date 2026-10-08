<?php
/* grace.php — friendly shortcut. The Grace collections portal lives inside
   index.php (?portal=grace). Redirect any direct hits there. */
header('Location: index.php?portal=grace', true, 301);
exit;
