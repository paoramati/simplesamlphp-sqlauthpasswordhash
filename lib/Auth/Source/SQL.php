<?php

/**
 * SQL/passwordhash authentication source
 *
 * An sql authentication source which authenticates an user
 * against a SQL database. Tests password hashes which have been 
 * generated using the php 7+ standard of the password_hash method, that is
 * without requiring a stored hash.
 * 
 * see: http://php.net/manual/en/function.password-hash.php
 * 
 * "The salt option has been deprecated as of PHP 7.0.0. It is now preferred 
 * to simply use the salt that is generated by default."
 * 
 * Store password hashes by generating them using the password_hash method:
 * 
 * e.g.
 * 
 * $plainTextPassword = "password";
 * $hashedPassword = password_hash($plainTextPassword, PASSWORD_DEFAULT);
 * 
 * This module is based on the sqlauth:SQL and sqlauthbcrypt:SQL modules.
 * 
 * @author Brandon Mitchell Paul
 * @package simpleSAMLphp
 */

class sspmod_sqlauthpasswordhash_Auth_Source_SQL extends sspmod_core_Auth_UserPassBase
{
    /**
     * The DSN we should connect to.
     */
    private $dsn;

    /**
     * The username we should connect to the database with.
     */
    private $username;

    /**
     * The password we should connect to the database with.
     */
    private $password;

    /**
     * The query we should use to retrieve the attributes for the user.
     *
     * The username and password will be available as :username and :password.
     */
    private $query;

	/**
	 * The column holding the password hash.
	 */
	private $hash_column;

    /**
     * Constructor for this authentication source.
     *
     * @param array $info  Information about this authentication source.
     * @param array $config  Configuration.
     */
    public function __construct($info, $config)
    {
        assert(is_array($info));
        assert(is_array($config));

        // Call the parent constructor first, as required by the interface
        parent::__construct($info, $config);

        // Make sure that all required parameters are present.
        foreach (array('dsn', 'username', 'password', 'query') as $param) {
            if (!array_key_exists($param, $config)) {
                throw new Exception('Missing required attribute \'' . $param .
                    '\' for authentication source ' . $this->authId);
            }

            if (!is_string($config[$param])) {
                throw new Exception('Expected parameter \'' . $param .
                    '\' for authentication source ' . $this->authId .
                    ' to be a string. Instead it was: ' .
                    var_export($config[$param], true));
            }
        }

        $this->dsn = $config['dsn'];
        $this->username = $config['username'];
        $this->password = $config['password'];
        $this->query = $config['query'];
        $this->hash_column = $config['hash_column'];
    }


    /**
     * Create a database connection.
     *
     * @return PDO  The database connection.
     */
    private function connect()
    {
        try {
            $db = new PDO($this->dsn, $this->username, $this->password);
        } catch (PDOException $e) {
            throw new Exception('sqlauthpasswordhash:' . $this->authId . ': - Failed to connect to \'' .
                $this->dsn . '\': '. $e->getMessage());
        }

        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $driver = explode(':', $this->dsn, 2);
        $driver = strtolower($driver[0]);

        /* Driver specific initialization. */
        switch ($driver) {
        case 'mysql':
            /* Use UTF-8. */
            $db->exec("SET NAMES 'utf8mb4'");
            break;
        case 'pgsql':
            /* Use UTF-8. */
            $db->exec("SET NAMES 'UTF8'");
            break;
        }

        return $db;
    }


    /**
     * Attempt to log in using the given username and password.
     *
     * On a successful login, this function should return the users attributes. On failure,
     * it should throw an exception. If the error was caused by the user entering the wrong
     * username or password, a SimpleSAML_Error_Error('WRONGUSERPASS') should be thrown.
     *
     * Note that both the username and the password are UTF-8 encoded.
     *
     * @param string $username  The username the user wrote.
     * @param string $password  The password the user wrote.
     * @return array  Associative array with the users attributes.
     */
    protected function login($username, $password)
    {
        assert(is_string($username));
        assert(is_string($password));

        $db = $this->connect();

        try {
            $sth = $db->prepare($this->query);
        } catch (PDOException $e) {
            throw new Exception('sqlauthpasswordhash:' . $this->authId .
                ': - Failed to prepare query: ' . $e->getMessage());
        }

        try {
            $sth->execute(array('username' => $username));
        } catch (PDOException $e) {
            throw new Exception('sqlauthpasswordhash:' . $this->authId .
                ': - Failed to execute query: ' . $e->getMessage());
        }

        try {
            $data = $sth->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new Exception('sqlauthpasswordhash:' . $this->authId .
                ': - Failed to fetch result set: ' . $e->getMessage());
        }

        SimpleSAML\Logger::info('sqlauthpasswordhash:' . $this->authId . ': Got ' . count($data) .
            ' rows from database');

        if (count($data) === 0) {
            /* No rows returned - invalid username */
            SimpleSAML\Logger::error('sqlauthpasswordhash:' . $this->authId .
                ': No rows in result set. Wrong username or sqlauthpasswordhash is misconfigured.');
            throw new SimpleSAML_Error_Error('WRONGUSERPASS');
        }

        /* Validate stored password hash (must be in first row of resultset) */ //TODO is this required?
        $password_hash = $data[0][$this->hash_column];

        // Verify stored hash matches standard php password_verify 
        if (!password_verify($password, $password_hash)) {
            /* Invalid password */
            SimpleSAML\Logger::error('sqlauthpasswordhash:' . $this->authId .
                ': Hash does not match. Wrong password or sqlauthpasswordhash is misconfigured.');
            throw new SimpleSAML_Error_Error('WRONGUSERPASS');
        }

        /* Extract attributes. We allow the resultset to consist of multiple rows. Attributes
         * which are present in more than one row will become multivalued. null values and
         * duplicate values will be skipped. All values will be converted to strings.
         */
        $attributes = array();
        foreach ($data as $row) {
            foreach ($row as $name => $value) {

                if ($value === null) {
                    continue;
                }

		if ($name === $this->hash_column) {
			/* Don't add password hash to attributes */
			continue;
		}

                $value = (string)$value;

                if (!array_key_exists($name, $attributes)) {
                    $attributes[$name] = array();
                }

                if (in_array($value, $attributes[$name], true)) {
                    /* Value already exists in attribute. */
                    continue;
                }

                $attributes[$name][] = $value;
            }
        }

        SimpleSAML\Logger::info('sqlauthpasswordhash:' . $this->authId . ': Attributes: ' .
            implode(',', array_keys($attributes)));

        return $attributes;
    }
}
