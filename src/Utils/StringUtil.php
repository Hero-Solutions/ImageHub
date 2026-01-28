<?php

namespace App\Utils;


class StringUtil
{
    public static function stripExtension(string $filename): string
    {
        $dotIndex = strrpos($filename, '.');
        if($dotIndex > 0) {
            $filename = substr($filename, 0, $dotIndex);
        }
        return $filename;
    }

    public static function getDateRange(string $earliestDate, string $latestDate): string
    {
        return StringUtil::getFullDate($earliestDate, '01', '01') . '/' . StringUtil::getFullDate($latestDate,'12', '31');
    }

    public static function getFullDate(string $date, string $monthAppend, string $dayAppend): string
    {
        if(preg_match('/^[0-9]+-[0-9]+-[0-9]+$/', $date)) {
            return $date;
        } else if(preg_match('/^[0-9]+-[0-9]+$/', $date)) {
            return $date . '-' . $dayAppend;
        } else if(preg_match('/^[0-9]+$/', $date)) {
            return $date . '-' . $monthAppend . '-' . $dayAppend;
        } else {
            return '';
        }
    }

    public static function filterPhotographer(?string $name): ?string
    {
        if($name === null) {
            return null;
        }

        $filters = array(
            'Image courtesy of ',
            'Fotograaf: ',
            'Fotograaf:',
            'fotograaf: ',
            'fotograaf:',
            'Foto: ',
            'Foto:',
            'foto: ',
            'foto:',
            'Photographer: ',
            'Photographer:',
            'photographer: ',
            'photographer:',
            'Photo: ',
            'Photo:',
            'photo: ',
            'photo:'
        );
        foreach($filters as $filter) {
            $name = str_replace($filter, '', $name);
        }
        return $name;
    }

    public static function cleanObjectNumber(string $nr): string
    {
        // Patterns, see https://github.com/PACKED-vzw/resolver/blob/master/resolver/util.py, def cleanID
        $patterns = array(
            // Exceptions
            '- '   => '-',
            ' -'   => '-',
            '\)+$' => '',
            '\]+$' => '',
            '\°+$' => '',
            // Simple replacements
            '\.'   => '_',
            ' '    => '_',
            '\('   => '_',
            '\)'   => '_',
            '\['   => '_',
            '\]'   => '_',
            '\/'   => '_',
            '\?'   => '_',
            ','    => '_',
            '&'    => '_',
            '\+'   => '_',
            '°'    => '_',
            // Replace two or more underscores by a single underscore
            '__+'   => '_',
            // Remove any underscores at the end
            '_$'    => ''
        );

        // Keep repeating the replacement until there are no more
        $hasPattern = true;
        while($hasPattern) {
            $hasPattern = false;
            foreach ($patterns as $pattern => $replacement) {
                if(preg_match('/^.*' . $pattern . '.*$/', $nr)) {
                    $hasPattern = true;
                    if(strpos($pattern, '$') < 0) {
                        $pattern = $pattern . '(.*)$';
                    }
                    $nr = preg_replace('/^(.*)' . $pattern . '/', '${1}' . $replacement . '${2}', $nr);
                }
            }
        }
        return $nr;
    }
}
