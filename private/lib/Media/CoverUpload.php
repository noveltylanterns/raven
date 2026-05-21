<?php

/**
 * RAVEN CMS
 * ~/private/lib/Media/CoverUpload.php
 * Cover-image upload helper: user cover filesystem writes and entity record payload builder.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Lib\Media;

/**
 * Handles cover-image uploads for users (filesystem writes) and other entities (record payloads).
 *
 * The storeForUser method handles per-user cover image storage under
 * `public/uploads/user/{userId}/cover.ext`. The recordPayload method builds the
 * database column map used by taxonomy and channel cover-image saves.
 */
final class CoverUpload
{
    private ?AvatarUpload $avatarUpload = null;
    /**
     * Builds persisted cover-image storage columns for one entity update.
     *
     * @param string $entityType Entity type such as categories/channels/groups/tags.
     * @param string $filename Stored source filename.
     * @param array<string, string> $paths Stored source+variant paths.
     * @return array<string, string|null>
     */
    public function recordPayload(string $entityType, string $filename, array $paths): array
    {
        // Some entity types still persist filename-only cover columns for compatibility.
        if (PreviewConfig::supportsFilenameStorage($entityType)) {
            return ['cover_image' => $filename];
        }

        return [
            'cover_image_path' => $paths['cover_image_path'] ?? null,
            'cover_image_sm_path' => $paths['cover_image_sm_path'] ?? null,
            'cover_image_md_path' => $paths['cover_image_md_path'] ?? null,
            'cover_image_lg_path' => $paths['cover_image_lg_path'] ?? null,
        ];
    }

    /**
     * Stores one cover image upload for a user and returns the stored relative path.
     *
     * Writes the file to `public/uploads/user/{userId}/cover.ext` under the given
     * project root. No thumbnail is generated for cover images.
     *
     * @param int $userId Numeric user id used as the per-user directory name.
     * @param array<string, mixed> $upload Validated upload payload from the panel file input.
     * @param string $extension Submitted or pre-normalized extension token.
     * @param string $projectRoot Absolute project root for filesystem path resolution.
     * @return array{ok: bool, path?: string, error?: string} Result with stored relative path on success.
     */
    public function storeForUser(int $userId, array $upload, string $extension, string $projectRoot): array
    {
        $normalizedExtension = $this->avatarUpload()->normalizeExtension($extension);
        // Reuse avatar extension policy so cover uploads share the same safe format rules.
        if ($normalizedExtension === null) {
            return ['ok' => false, 'error' => 'Cover image upload format is not supported.'];
        }

        $directory = $this->userDirectory($userId, $projectRoot);
        $filename = 'cover.' . $normalizedExtension;
        $destination = $directory . '/' . $filename;
        $storeError = $this->avatarUpload()->storeSanitizedImageUpload($upload, $destination);
        // Forward sanitizer failures directly so callers see the exact upload issue.
        if ($storeError !== null) {
            return ['ok' => false, 'error' => $storeError];
        }

        return ['ok' => true, 'path' => 'uploads/user/' . $userId . '/' . $filename];
    }

    /**
     * Resolves and creates the per-user upload directory for a given user id.
     *
     * @param int $userId Numeric user id used as the directory name segment.
     * @param string $projectRoot Absolute project root.
     * @return string Absolute path to the user upload directory.
     */
    private function userDirectory(int $userId, string $projectRoot): string
    {
        $directory = rtrim($projectRoot, '/\\') . '/public/uploads/user/' . $userId;
        // Create per-user cover directory lazily on first upload.
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        return $directory;
    }

    /**
     * Lazily resolves the AvatarUpload helper used for image sanitization and extension normalization.
     *
     * Reuses AvatarUpload's image processing pipeline rather than duplicating it for cover images,
     * since both formats go through the same decode/re-encode stripping path.
     *
     * @return AvatarUpload
     */
    private function avatarUpload(): AvatarUpload
    {
        // Lazily construct shared upload sanitizer to avoid duplicate setup.
        if (!$this->avatarUpload instanceof AvatarUpload) {
            $this->avatarUpload = new AvatarUpload();
        }

        return $this->avatarUpload;
    }
}
