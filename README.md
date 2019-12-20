## Getting Started

Deploy

    $ mkdir api
    $ cd api
    $ git clone https://gitlab.csi.it/prodotti/machi/machibff.git v1
    $ cd v1
    $ composer install

Create databse

    $ chmod -x bin/createdb
    $ bin/createdb

or

    $ php bin/createdb