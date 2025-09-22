#!/bin/bash
user=$1
repo=$2
[ -d "$repo" ] && (cd "$repo" && git pull && cd ..) || git clone "https://github.com/$user/$repo.git"
cd "$repo"
git submodule update --init --recursive
shift 2
[ -f "init.sh" ] && ./init.sh
$@

