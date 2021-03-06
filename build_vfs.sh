#!/bin/bash

## Note to self:
# Run this script on:
#   - x86_64: gb@fileserver2:~
#   - i386:   bougu@macbook:~/VirtualBox VMs/Ubuntu (32-bit)
#   - ARM:    gb@fileserver2:~/qemu-arm
# And don't forget to get the latest version of the samba-module from fileserver2 first:
#   scp -r gb@192.168.155.88:Greyhole ~

export GREYHOLE_INSTALL_DIR="/home/gb/Greyhole"
export HOME="/home/gb"

###

cd "$HOME"

cd samba-3.4.9/source3
    if  [ ! -f bin/greyhole.so ]; then
        ./configure
        patch -p1 < ${GREYHOLE_INSTALL_DIR}/samba-module/Makefile-samba-3.4.patch
    fi
    rm -f modules/vfs_greyhole.c
    ln -s ${GREYHOLE_INSTALL_DIR}/samba-module/vfs_greyhole-samba-3.4.c modules/vfs_greyhole.c
    make -j4
cd ../..

cd samba-3.5.4/source3
    if  [ ! -f bin/greyhole.so ]; then
        ./configure
        patch -p1 < ${GREYHOLE_INSTALL_DIR}/samba-module/Makefile-samba-3.5.patch
    fi
    rm -f modules/vfs_greyhole.c
    ln -s ${GREYHOLE_INSTALL_DIR}/samba-module/vfs_greyhole-samba-3.5.c modules/vfs_greyhole.c
    make -j4
cd ../..

cd samba-3.6.9/source3
    if  [ ! -f bin/greyhole.so ]; then
        ./configure
        patch -p1 < ${GREYHOLE_INSTALL_DIR}/samba-module/Makefile-samba-3.6.patch
    fi
    rm -f modules/vfs_greyhole.c
    ln -s ${GREYHOLE_INSTALL_DIR}/samba-module/vfs_greyhole-samba-3.6.c modules/vfs_greyhole.c
    make -j4
cd ../..

cd samba-4.0.14
    if  [ ! -f bin/default/source3/modules/libvfs-greyhole.so ]; then
        patch -p1 < ${GREYHOLE_INSTALL_DIR}/samba-module/wscript-samba-4.0.patch
        ./configure --enable-debug --enable-selftest --disable-symbol-versions
    fi
    rm -f source3/modules/vfs_greyhole.c
    ln -s ${GREYHOLE_INSTALL_DIR}/samba-module/vfs_greyhole-samba-4.0.c source3/modules/vfs_greyhole.c
    make -j4
cd ..

cd samba-4.1.4
    if  [ ! -f bin/default/source3/modules/libvfs-greyhole.so ]; then
        patch -p1 < ${GREYHOLE_INSTALL_DIR}/samba-module/wscript-samba-4.1.patch
        ./configure --enable-debug --enable-selftest --disable-symbol-versions
    fi
    rm -f source3/modules/vfs_greyhole.c
    ln -s ${GREYHOLE_INSTALL_DIR}/samba-module/vfs_greyhole-samba-4.1.c source3/modules/vfs_greyhole.c
    make -j4
cd ..

echo
echo "****************************************"
echo

ARCH="`uname -i`"
if [ "$ARCH" = "unknown" ]; then
    ARCH="arm"
fi

if  [ -f samba-3.4.9/source3/bin/greyhole.so ]; then
    ls -1 samba-3.4.9/source3/bin/greyhole.so
    cp samba-3.4.9/source3/bin/greyhole.so ${GREYHOLE_INSTALL_DIR}/samba-module/bin/3.4/greyhole-$ARCH.so
    echo " was copied to "
    ls -1 ${GREYHOLE_INSTALL_DIR}/samba-module/bin/3.4/greyhole-$ARCH.so
    echo
fi

if  [ -f samba-3.5.4/source3/bin/greyhole.so ]; then
    ls -1 samba-3.5.4/source3/bin/greyhole.so
    cp samba-3.5.4/source3/bin/greyhole.so ${GREYHOLE_INSTALL_DIR}/samba-module/bin/3.5/greyhole-$ARCH.so
    echo " was copied to "
    ls -1 ${GREYHOLE_INSTALL_DIR}/samba-module/bin/3.5/greyhole-$ARCH.so
    echo
fi

if  [ -f samba-3.6.9/source3/bin/greyhole.so ]; then
    ls -1 samba-3.6.9/source3/bin/greyhole.so
    cp samba-3.6.9/source3/bin/greyhole.so ${GREYHOLE_INSTALL_DIR}/samba-module/bin/3.6/greyhole-$ARCH.so
    echo " was copied to "
    ls -1 ${GREYHOLE_INSTALL_DIR}/samba-module/bin/3.6/greyhole-$ARCH.so
    echo
fi

if  [ -f samba-4.0.14/bin/default/source3/modules/libvfs-greyhole.so ]; then
    ls -1 samba-4.0.14/bin/default/source3/modules/libvfs-greyhole.so
    cp samba-4.0.14/bin/default/source3/modules/libvfs-greyhole.so ${GREYHOLE_INSTALL_DIR}/samba-module/bin/4.0/greyhole-$ARCH.so
    echo " was copied to "
    ls -1 ${GREYHOLE_INSTALL_DIR}/samba-module/bin/4.0/greyhole-$ARCH.so
    echo
fi

if  [ -f samba-4.1.4/bin/default/source3/modules/libvfs-greyhole.so ]; then
    ls -1 samba-4.1.4/bin/default/source3/modules/libvfs-greyhole.so
    cp samba-4.1.4/bin/default/source3/modules/libvfs-greyhole.so ${GREYHOLE_INSTALL_DIR}/samba-module/bin/4.1/greyhole-$ARCH.so
    echo " was copied to "
    ls -1 ${GREYHOLE_INSTALL_DIR}/samba-module/bin/4.1/greyhole-$ARCH.so
    echo
fi

echo "****************************************"
echo

exit
SSH_HOST="gb@192.168.155.214"
ARCH="i386"
cd ~/git/Greyhole/samba-module/bin/
scp $SSH_HOST:Greyhole/samba-module/bin/3.4/greyhole-$ARCH.so 3.4/greyhole-$ARCH.so
scp $SSH_HOST:Greyhole/samba-module/bin/3.5/greyhole-$ARCH.so 3.5/greyhole-$ARCH.so
scp $SSH_HOST:Greyhole/samba-module/bin/3.6/greyhole-$ARCH.so 3.6/greyhole-$ARCH.so
scp $SSH_HOST:Greyhole/samba-module/bin/4.0/greyhole-$ARCH.so 4.0/greyhole-$ARCH.so
scp $SSH_HOST:Greyhole/samba-module/bin/4.1/greyhole-$ARCH.so 4.1/greyhole-$ARCH.so
