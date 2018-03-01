<?php
//global default settings

/* *************************** Required variables ***************************
As of version 0.2.0.1 variables below this line are required for OSM to operate correctly
*/

//set system wide version for php scripts
$_gVersion='0.2.0.2';

//set the default time chrome will wait between phone home attempst to the upload script
$_gUploadRefreshTime=9000;

//set lock file timeout to avoid locking on stale lock request
$_gLockTimeout=300;

//set the OSM lab filter message
$_gFilterMessage = array('title' => 'OSM Server says ... ', 'message' => array('newtab' => 'A lab filter violation was detected on the url request of: ', 'opentab' => 'A lab filter violation was detected on an existing tab url of: '));
