#!/bin/bash

basepath=${0%/*}
file=$basepath"/php-mailer.php"
phppath=`which php`
checksudo=`whoami`

if [ "$checksudo" != "root" ]; then
  echo "Please run with root"
  exit
fi

$phppath $file