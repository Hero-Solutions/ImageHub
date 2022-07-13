<?php
/**
 * Created by PhpStorm.
 * User: mike
 * Date: 10/29/19
 * Time: 4:44 PM
 */

namespace App\Utils;


use SimpleSAML\Auth\Simple;

class Authenticator
{
    public static function authenticate($adfsRequirements)
    {
        if($adfsRequirements['public']) {
            return true;
        } else {
            $auth = new Simple('default-sp');
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

    public static function isAllowed($attributes, $adfsRequirements)
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