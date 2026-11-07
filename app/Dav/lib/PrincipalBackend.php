<?php
// One principal per mailbox, and a mailbox can only ever see ITSELF: no
// listing or search reveals any other address on the server. No sharing,
// no groups (v1).
namespace KarnakDav;

use Sabre\DAV\PropPatch;
use Sabre\DAVACL\PrincipalBackend\AbstractBackend;

final class PrincipalBackend extends AbstractBackend
{
    private string $me = '';   // authenticated principal uri, set after auth

    public function setCurrent(string $principalUri): void
    {
        $this->me = $principalUri;
    }

    private function row(string $uri): ?array
    {
        if ($this->me === '' || $uri !== $this->me) return null;
        $addr = substr($uri, strlen('principals/'));
        return [
            'uri' => $uri,
            '{DAV:}displayname' => $addr,
            '{http://sabredav.org/ns}email-address' => $addr,
        ];
    }

    public function getPrincipalsByPrefix($prefixPath)
    {
        if ($prefixPath !== 'principals') return [];
        $r = $this->row($this->me);
        return $r ? [$r] : [];
    }

    public function getPrincipalByPath($path)
    {
        return $this->row((string)$path) ?? [];
    }

    public function updatePrincipal($path, PropPatch $propPatch)
    {
        // read-only (display name = the address)
    }

    public function searchPrincipals($prefixPath, array $searchProperties, $test = 'allof')
    {
        if ($prefixPath !== 'principals' || $this->me === '') return [];
        $addr = substr($this->me, strlen('principals/'));
        $hits = [];
        foreach ($searchProperties as $prop => $value) {
            $value = strtolower((string)$value);
            if ($prop === '{http://sabredav.org/ns}email-address' || $prop === '{DAV:}displayname') {
                $hits[] = $value !== '' && str_contains($addr, $value);
            } else {
                $hits[] = false;
            }
        }
        if (!$hits) return [];
        $match = $test === 'anyof' ? in_array(true, $hits, true) : !in_array(false, $hits, true);
        return $match ? [$this->me] : [];
    }

    public function findByUri($uri, $principalPrefix)
    {
        $uri = (string)$uri;
        if (stripos($uri, 'mailto:') !== 0 || $this->me === '') return null;
        $addr = strtolower(substr($uri, 7));
        return Store::principalUri($addr) === $this->me ? $this->me : null;
    }

    public function getGroupMemberSet($principal)
    {
        return [];
    }

    public function getGroupMembership($principal)
    {
        return [];
    }

    public function setGroupMemberSet($principal, array $members)
    {
        throw new \Sabre\DAV\Exception\Forbidden('Groups are not supported');
    }
}
