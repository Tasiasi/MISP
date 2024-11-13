<?php

App::uses('BaseAuthenticate', 'Controller/Component/Auth');

class LdapAuthenticate extends BaseAuthenticate
{

    /**
     * Holds the user information
     *
     * @var array
     */
    protected static $user = false;

    protected static $conf;

    /* 
    'LdapAuth' => [
        'ldapHost' => 'ldap://openldap:389',
        'ldapDn' => 'dc=example,dc=com',
        'ldapReaderUser' => 'cn=reader,dc=example,dc=com',
        'ldapReaderPassword' => 'readerpassword',
        'ldapSearchFilter' => ''
    ]
    */

    public function __construct()
    {
        self::$conf = [
            'ldapServer' => Configure::read('LdapAuth.ldapServer'),
            'ldapDn' => Configure::read('LdapAuth.ldapDn'),
            'ldapReaderUser' => Configure::read('LdapAuth.ldapReaderUser'),
            'ldapReaderPassword' => Configure::read('LdapAuth.ldapReaderPassword'),
            'ldapSearchFilter' => Configure::read('LdapAuth.ldapSearchFilter'),
            'ldapSearchAttribute' => Configure::read('LdapAuth.ldapSearchAttribute') ?? 'mail',
            'ldapEmailField' => Configure::read('LdapAuth.ldapEmailField') ?? ['mail'],
            'ldapNetworkTimeout' => Configure::read('LdapAuth.ldapNetworkTimeout') ?? -1,
            'ldapProtocol' => Configure::read('LdapAuth.ldapProtocol') ?? 3,
            'ldapAllowReferrals' => Configure::read('LdapAuth.ldapAllowReferrals') ?? false,
            'starttls' => Configure::read('LdapAuth.starttls') ?? false,
            'mixedAuth' => Configure::read('LdapAuth.mixedAuth') ?? false,
            'ldapDefaultOrg' => Configure::read('LdapAuth.ldapDefaultOrg'),
            'ldapDefaultRoleId' => Configure::read('LdapAuth.ldapDefaultRoleId') ?? 3,
            'updateUser' => Configure::read('LdapAuth.updateUser') ?? true,
        ];
    }

    public function authenticate(CakeRequest $request, CakeResponse $response)
    {
        // Try to authenticate the incoming request against the LDAP backend
        $user = $this->getUser($request);

        return $user;
    }

    private function ldapConnect()
    {
        // LDAP connection
        ldap_set_option(NULL, LDAP_OPT_NETWORK_TIMEOUT, self::$conf['ldapNetworkTimeout']);
        $ldapconn = ldap_connect(self::$conf['ldapServer']);

        if (!$ldapconn) {
            CakeLog::error("[LdapAuth] LDAP server connection failed.");
            throw new UnauthorizedException(__('User could not be authenticated by LDAP.'));
        }

        // LDAP protocol configuration
        ldap_set_option($ldapconn, LDAP_OPT_PROTOCOL_VERSION, self::$conf['ldapProtocol']);
        ldap_set_option($ldapconn, LDAP_OPT_REFERRALS, self::$conf['ldapAllowReferrals']);

        if (self::$conf['starttls'] == true) {
            # Default is false, sine STARTTLS support is a new feature
            # Ignored on ldaps://, but can trigger problems for orgs
            # using unencrypted LDAP. Loose comparison allows users to
            # use # true / 1 / etc.
            ldap_start_tls($ldapconn);
        }

        return $ldapconn;
    }

    private function getEmailAddress($ldapEmailField, $ldapUserData)
    {
        // return the email address of an LDAP user if one of the fields in $ldapEmaiLField exists
        foreach ($ldapEmailField as $field) {
            if (isset($ldapUserData[0][$field][0])) {
                return $ldapUserData[0][$field][0];
            }
        }
        return null;
    }

    private function getUserMemberships($ldapconn, $ldapUserData)
    {
        $groups = [];
        $filter = '(member= ' . $ldapUserData[0]['dn'] . ')';
        $ldapUserMemberships = ldap_search($ldapconn, self::$conf['ldapDn'], $filter, ['cn']);

        if ($ldapUserMemberships) {
            $entries = ldap_get_entries($ldapconn, $ldapUserMemberships);
            foreach ($entries as $entry) {
                if (is_array($entry) && isset($entry[0])) {
                    $groups[] = $entry['cn'][0];
                }
            }
        }

        return $groups;
    }

    private function disableUser($mispUsername)
    {
        $userModel = ClassRegistry::init($this->settings['userModel']);
        $user = $this->_findUser($mispUsername);
        $user['disabled'] = 1;
        $userModel->save($user, false);
    }

    private function getLdapUserData($ldapconn, $email)
    {
        // LDAP search filter
        $filter = '(' . self::$conf['ldapSearchAttribute'] . '=' . $email . ')';
        if (!empty(self::$conf['ldapSearchFilter'])) {
            $filter =  '(&' . self::$conf['ldapSearchFilter'] . $filter . ')';
        }

        $ldapUser = ldap_search($ldapconn, self::$conf['ldapDn'], $filter, ['mail']);

        if (!$ldapUser) {
            CakeLog::error("[LdapAuth] LDAP user search failed: " . ldap_error($ldapconn));
            throw new UnauthorizedException(__('User could not be authenticated by LDAP.'));
        }

        # Get user data
        $ldapUserData = ldap_get_entries($ldapconn, $ldapUser);
        if (!$ldapUserData) {
            CakeLog::error("[LdapAuth] LDAP get user entries failed: " . ldap_error($ldapconn));
            throw new UnauthorizedException(__('User could not be authenticated by LDAP.'));
        }

        return $ldapUserData;
    }

    /*
     * Retrieve a user by validating the request data
     */
    public function getUser($request)
    {
        if (!array_key_exists("User", $request->data)) {
            return false;
        }

        $userFields = $request->data['User'];
        $email = $userFields['email'];
        $password = $userFields['password'];

        CakeLog::debug("[LdapAuth] Login attempt with email: $email");
        $this->settings['fields'] = ["username" => "email"];

        $ldapconn = $this->ldapConnect();

        $ldapUserData = $this->getLdapUserData($ldapconn, $email);

        if ($ldapUserData['count'] == 0) {
            // If the user is not found in LDAP, try to authenticate against the local database if `mixedAuth` is enabled
            if (self::$conf['mixedAuth'] == true) {
                $this->settings['fields'] += ["password" => "password"];
                $this->settings['passwordHasher'] = "BlowfishConstant";
                return $this->_findUser($email, $password);
            } else {
                CakeLog::error("[LdapAuth] User not found in LDAP.");
                throw new UnauthorizedException(__('User could not be authenticated by LDAP.'));
            }
        }

        // Try to log-in with user LDAP password
        $ldapbind = ldap_bind($ldapconn, $ldapUserData[0]['dn'], $password);
        if (!$ldapbind) {
            CakeLog::error("[LdapAuth] LDAP user authentication failed: " . ldap_error($ldapconn));
            return false;
        }

        if (!isset(self::$conf['ldapEmailField']) && isset($ldapUserData[0]['mail'][0])) {
            // Assign the real user for MISP
            $mispUsername = $ldapUserData[0]['mail'][0];
        } else if (isset(self::$conf['ldapEmailField'])) {
            $mispUsername = $this->getEmailAddress(self::$conf['ldapEmailField'], $ldapUserData);
        } else {
            CakeLog::error("[LdapAuth] User not found in LDAP.");
            throw new UnauthorizedException(__('User could not be authenticated by LDAP.'));
        }

        // Find user with real username (mail)
        $user = $this->_findUser($mispUsername);

        if ($user && !self::$conf['updateUser']) {
            return $user;
        }

        // Insert user in database if not existent
        $userModel = ClassRegistry::init($this->settings['userModel']);
        $org_id = self::$conf['ldapDefaultOrg'];

        // If not in config, take first local organisation
        if (!isset($org_id)) {
            $firstOrg = $userModel->Organisation->find(
                'first',
                [
                    'conditions' => [
                        'Organisation.local' => true
                    ],
                    'order' => 'Organisation.id ASC'
                ]
            );
            $org_id = $firstOrg['Organisation']['id'];
        }

        // Set role_id based on group membership or default role
        if (is_array(self::$conf['ldapDefaultRoleId'])) {
            // Get user memberships
            $groups = $this->getUserMemberships($ldapconn, $ldapUserData);

            // Find the role ID if the user belongs to any of the specified groups
            $roleId = null;
            foreach ($groups as $group) {
                if (isset(self::$conf['ldapDefaultRoleId'][$group])) {
                    $roleId = self::$conf['ldapDefaultRoleId'][$group];
                    break;
                }
            }

            // Disable user if no valid role is found
            if ($user && !$roleId) {
                CakeLog::debug("[LdapAuth] User has no valid role anymore, disabling user.");
                $this->disableUser($mispUsername);
                throw new UnauthorizedException(__('User could not be authenticated by LDAP.'));
            }
        } else {
            $roleId = self::$conf['ldapDefaultRoleId'];
        }

        if (!$user) {
            // Create user
            $userData = ['User' => [
                'email' => $mispUsername,
                'org_id' => $org_id,
                'password' => '',
                'confirm_password' => '',
                'authkey' => $userModel->generateAuthKey(),
                'nids_sid' => 4000000,
                'newsread' => 0,
                'role_id' => $roleId,
                'change_pw' => 0,
            ]];
            $userModel->save($userData, false);
        } else {
            // Update existing user
            $user['email'] = $mispUsername;
            $user['org_id'] = $org_id;
            $user['role_id'] = $roleId;
            # Reenable user in case it has been disabled
            $user['disabled'] = 0;

            $userModel->save($user, false);
        }

        return $this->_findUser(
            $mispUsername
        );
    }
}
