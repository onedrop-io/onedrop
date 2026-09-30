<?php

test('the short install URL redirects to the install script on main', function () {
    $this->get('/install')
        ->assertRedirect('https://raw.githubusercontent.com/onedrop-io/onedrop/main/install.sh');
})->group('INSTALL-001');
