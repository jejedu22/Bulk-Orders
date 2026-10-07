<?php
namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;

class OptionsSettings
{
    /** @var \Doctrine\DBAL\Connection */
    private $db;
    private $em;

    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
        $this->db = $this->em->getConnection();
    }

    /**
     * Gets a setting value. 
     * If the setting doesn't exist, returns the default value specified as second param
     */
    public function get(string $name, $default=''): string
    {
        $value = $this->db->fetchOne('SELECT `value` FROM `settings` WHERE `name` = ?', [$name]);

        return $value ?: $default;
    }

    /**
     * Sets a setting value. 
     * If the setting doesn't exists, it creates it. Otherwise, it replaces the db value
     */
    public function set(string $name, string $value): void
    {
        // La colonne name n'est pas unique : pas d'« ON DUPLICATE KEY »
        if (false !== $this->db->fetchOne('SELECT `id` FROM `settings` WHERE `name` = ?', [$name])) {
            $this->db->executeStatement('UPDATE `settings` SET `value` = ? WHERE `name` = ?', [$value, $name]);
        } else {
            $this->db->executeStatement('INSERT INTO `settings` (`name`, `value`) VALUES (?, ?)', [$name, $value]);
        }
    }

    public function getObfuscatedEmailAddress($email)
    {
        $alwaysEncode = array('.', ':', '@');

        $result = '';

        // Encode string using oct and hex character codes
        for ($i = 0; $i < strlen($email); $i++) {
            // Encode 25% of characters including several that always should be encoded
            if (in_array($email[$i], $alwaysEncode) || mt_rand(1, 100) < 25) {
                if (mt_rand(0, 1)) {
                    $result .= '&#' . ord($email[$i]) . ';';
                } else {
                    $result .= '&#x' . dechex(ord($email[$i])) . ';';
                }
            } else {
                $result .= $email[$i];
            }
        }

        return $result;
    }

    public function getObfuscatedEmailLink($email, $params = array())
    {
        if (!is_array($params)) {
            $params = array();
        }

        // Tell search engines to ignore obfuscated uri
        if (!isset($params['rel'])) {
            $params['rel'] = 'nofollow';
        }

        $neverEncode = array('.', '@', '+'); // Don't encode those as not fully supported by IE & Chrome

        $urlEncodedEmail = '';
        for ($i = 0; $i < strlen($email); $i++) {
            // Encode 25% of characters
            if (!in_array($email[$i], $neverEncode) && mt_rand(1, 100) < 25) {
                $charCode = ord($email[$i]);
                $urlEncodedEmail .= '%';
                $urlEncodedEmail .= dechex(($charCode >> 4) & 0xF);
                $urlEncodedEmail .= dechex($charCode & 0xF);
            } else {
                $urlEncodedEmail .= $email[$i];
            }
        }

        $obfuscatedEmail = $this->getObfuscatedEmailAddress($email);
        $obfuscatedEmailUrl = $this->getObfuscatedEmailAddress('mailto:' . $urlEncodedEmail);

        $link = '<a class="nav-link" href="' . $obfuscatedEmailUrl . '"';
        foreach ($params as $param => $value) {
            $link .= ' ' . $param . '="' . htmlspecialchars($value) . '"';
        }
        $link .= '><i class="fas fa-envelope"></i></a>';

        return $link;
    }
}