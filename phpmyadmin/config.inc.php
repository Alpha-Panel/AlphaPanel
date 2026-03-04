<?php
declare(strict_types=1);

$cfg['blowfish_secret'] = getenv('PMA_BLOWFISH_SECRET') ?: 'q!XcGz^#fAPRWMGTaj#KF&%FowP0kEQ@';

$cfg['DefaultLang'] = 'en';
$cfg['ServerDefault'] = 1;


$i = 1;
$cfg['Servers'][$i]['auth_type'] = 'cookie';
$cfg['Servers'][$i]['host'] = getenv('PMA_HOST') ?: 'mysql';
$cfg['Servers'][$i]['port'] = getenv('PMA_PORT') ?: '3306';
$cfg['Servers'][$i]['compress'] = false;
$cfg['Servers'][$i]['AllowNoPassword'] = false;


$i = 2;
$cfg['Servers'][$i]['auth_type'] = 'signon';
$cfg['Servers'][$i]['host'] = getenv('PMA_HOST') ?: 'mysql';
$cfg['Servers'][$i]['port'] = getenv('PMA_PORT') ?: '3306';
$cfg['Servers'][$i]['hide_db'] = '^(information_schema|performance_schema)$';
$cfg['Servers'][$i]['DisplayServersList'] = false;
$cfg['Servers'][$i]['NavigationDisplayServers'] = false;
$cfg['Servers'][$i]['SignonSession'] = 'PMA_SSO';
$cfg['Servers'][$i]['SignonURL']     = '/signon.php';
// SignonScript tanimlamiyoruz; signon.php yalnizca URL olarak calisacak.


$cfg['DisplayServersList'] = true;
