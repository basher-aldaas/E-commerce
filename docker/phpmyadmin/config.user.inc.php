<?php
// Local development only: Laravel's MySQL root user has an empty password,
// which phpMyAdmin refuses unless this is on.
for ($i = 1; isset($cfg['Servers'][$i]); $i++) {
    $cfg['Servers'][$i]['AllowNoPassword'] = true;
}
