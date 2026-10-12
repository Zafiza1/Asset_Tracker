<?php

namespace App\Domain\Shared\Masters;

use App\Domain\Shared\Exceptions\ApiException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Tree helpers for tables with `parent_id` + materialized `path` ("/<root>/.../<id>/"),
 * which lets scope and report queries select a node and all its descendants with one
 * prefix match.
 */
final class MaterializedPath
{
    public static function pathFor(Model $node, ?string $parentPath): string
    {
        return ($parentPath ?: '/').$node->getKey().'/';
    }

    /** Moves a node (and its whole subtree) under a new parent, rejecting cycles. */
    public static function move(Model $node, ?Model $parent): void
    {
        if ($parent !== null && str_contains((string) $parent->getAttribute('path'), '/'.$node->getKey().'/')) {
            throw ApiException::unprocessable('INVALID_PARENT', 'Induk tidak boleh berupa data itu sendiri atau turunannya.');
        }

        $oldPath = (string) $node->getAttribute('path');
        $newPath = self::pathFor($node, $parent?->getAttribute('path'));
        if ($oldPath === $newPath) {
            return;
        }

        $sql = "UPDATE {$node->getTable()} SET path = ? || substr(path, ?) WHERE path LIKE ?";
        $bindings = [$newPath, strlen($oldPath) + 1, $oldPath.'%'];
        if (in_array('organization_id', array_keys($node->getAttributes()), true)) {
            $sql .= ' AND organization_id = ?';
            $bindings[] = $node->getAttribute('organization_id');
        }
        DB::update($sql, $bindings);

        $node->setAttribute('path', $newPath);
        $node->syncOriginalAttribute('path');
    }
}
