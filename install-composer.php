<?php
copy('https://getcomposer.org/installer', 'composer-setup.php');
if (hash_file('sha384', 'composer-setup.php') === file_get_contents('https://composer.github.io/installer.sig')) {
    echo "Installer verified\n";
} else {
    echo "Installer corrupt\n";
    unlink('composer-setup.php');
    exit(1);
}
echo "Installing Composer...\n";
exec('php composer-setup.php');
unlink('composer-setup.php');
echo "Composer installed successfully!\n";
?> 