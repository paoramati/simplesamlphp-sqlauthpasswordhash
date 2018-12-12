# simplesamlphp-sqlauthpasswordhash
=============

This is a authentication module for authenticating an user against a SQL database, assuming that the recommended php password_hash mechanism has been used to store the hashed password.


Options
-------

`dsn`
:   The DSN which should be used to connect to the database server.
    Check the various database drivers in the [PHP documentation](http://php.net/manual/en/pdo.drivers.php) for a description of the various DSN formats.

`username`
:   The username which should be used when connecting to the database server.


`password`
:   The password which should be used when connecting to the database server.

`query`
:   The SQL query which should be used to retrieve the user.
    The parameters :username and :password are available.
    If the username/password is incorrect, the query should return no rows.
    The name of the columns in resultset will be used as attribute names.
    If the query returns multiple rows, they will be merged into the attributes.
    Duplicate values and NULL values will be removed.


Examples
--------

Database layout used in some of the examples:

    CREATE TABLE users (
      uid VARCHAR(30) NOT NULL PRIMARY KEY,
      password_hash TEXT NOT NULL,
      givenName TEXT NOT NULL,
      email TEXT NOT NULL,
      eduPersonPrincipalName TEXT NOT NULL
    );
    CREATE TABLE usergroups (
      uid VARCHAR(30) NOT NULL REFERENCES users (uid) ON DELETE CASCADE ON UPDATE CASCADE,
      groupname VARCHAR(30) NOT NULL,
      UNIQUE(uid, groupname)
    );

Password verification should be done with php.

See: http://php.net/manual/en/function.password-hash.php

<quote>The salt option has been deprecated as of PHP 7.0.0. It is now preferred 
to simply use the salt that is generated by default.</quote>
 
Store password hashes by generating them with the password_hash method with the parameters of the plaintext password 
and the PASSWORD_DEFAULT constant.:

e.g.

function addUser($username, $plainTextPassword) {
    $hashedPassword = password_hash($plainTextPassword, PASSWORD_DEFAULT);

    // Insert user record ...    
}

This method has the advantage of not needing a seperate salt stored in the database, and also allows for transparent improvements
of the algorithm by php engineers.

This authentication module can then use the php password_verify method to determine whether the hash of the user's input
password matches the stored hash.
