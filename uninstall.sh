#!/bin/bash

basepath=${0%/*}
file=$basepath"/run.sh"
checksudo=`whoami`

line="*/1 * * * * $file"


if [ "$checksudo" != "root" ]; then
  echo "Please run with root"
  exit
fi

crontab -l | sed "/^[^#]$line/s/^/#/" | crontab -
