<?php
/**
 * Created by PhpStorm.
 * User: mike
 * Date: 10/29/19
 * Time: 4:44 PM
 */

namespace App\Utils;


use SimpleSAML_Auth_Simple;

class Authenticator
{
    public static function authenticate($adfsRequirements): bool
    {
        if($adfsRequirements['public']) {
            return true;
        } else {
            $auth = new SimpleSAML_Auth_Simple('default-sp');
            if ($auth->isAuthenticated()) {
                return Authenticator::isAllowed($auth->getAttributes(), $adfsRequirements);
            } else {
                $auth->requireAuth();
                if ($auth->isAuthenticated()) {
                    return Authenticator::isAllowed($auth->getAttributes(), $adfsRequirements);
                } else {
                    return false;
                }
            }
        }
    }

    public static function isAllowed($attributes, $adfsRequirements): bool
    {
        $allowed = false;
        foreach ($attributes as $key => $values) {
            if ($adfsRequirements['key'] == $key) {
                foreach ($values as $value) {
                  foreach($adfsRequirements['values'] as $reqValue) {
                      if ($value == $reqValue) {
                          $allowed = true;
                          break;
                      }
                   }
                }
            }
        }
        return $allowed;
    }
}