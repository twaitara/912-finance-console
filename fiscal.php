<?php
/* fiscal.php — friendly shortcut. The Fiscalisation portal lives inside
   index.php (?portal=fiscal). Redirect any direct hits there. */
header('Location: index.php?portal=fiscal', true, 301);
exit;
