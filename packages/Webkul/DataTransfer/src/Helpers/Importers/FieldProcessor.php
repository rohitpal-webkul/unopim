<?php

namespace Webkul\DataTransfer\Helpers\Importers;

use Exception;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage as StorageFacade;
use Webkul\Core\Filesystem\FileStorer;

class FieldProcessor
{
    public function __construct(public FileStorer $fileStorer) {}

    /**
     * Processes a field value based on its type.
     *
     * @param  object  $field  The field object.
     * @param  mixed  $value  The value of the field.
     * @param  string  $path  The path to the media files.
     * @return mixed The processed value of the field.
     */
    public function handleField($field, mixed $value, string $path, string $importType)
    {
        if (empty($value)) {
            return;
        }

        switch ($field->type) {
            case 'gallery':
                $value = $this->handleMediaField($field->type, $value, $path, $importType);

                break;
            case 'image':
            case 'file':
                $value = $this->handleMediaField($field->type, $value, $path, $importType);
                if (is_array($value)) {
                    $value = implode(',', $value);
                }

                break;
            case 'textarea':
                if ($field->enable_wysiwyg) {
                    $value = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
                }

                break;
            default:
                break;
        }

        return $value;
    }

    /**
     * Processes media fields value.
     *
     * @param  mixed  $value  The value of the media field.
     * @param  string  $imgpath  The path to the media files.
     * @return array|null valid paths of the media files, or null if none are found.
     */
    protected function handleMediaField(string $attributeCode, mixed $value, string $imgpath, string $importType): ?array
    {
        $paths = is_array($value) ? $value : [$value];
        $validPaths = [];

        foreach ($paths as $path) {
            $trimmedPath = trim($path);

            if (filter_var($trimmedPath, FILTER_VALIDATE_URL)) {
                $imagePath = $importType.DIRECTORY_SEPARATOR.$attributeCode;

                if ($uploadedPath = $this->saveImageFromUrl($trimmedPath, $imagePath)) {
                    $validPaths[] = $uploadedPath;
                }
            } elseif (StorageFacade::has($imgpath.$trimmedPath)) {
                $validPaths[] = $imgpath.$trimmedPath;
            }
        }

        return count($validPaths) ? $validPaths : null;
    }

    protected function saveImageFromUrl(string $url, string $path, array $options = []): string
    {
        $response = Http::withOptions(['verify' => false])->get($url);

        if (! $response->successful()) {
            Log::error("Failed to fetch the image from URL: $url");
            return null;
        }

        $tempFilePath = tempnam(sys_get_temp_dir(), 'url_image_');
        try {
            file_put_contents($tempFilePath, $response->body());
        } catch (Exception $e) {
            Log::error("Unable to write temporary file for image URL: $url. Error: " . $e->getMessage());

            return null;
        }

        $tempFile = new File($tempFilePath);
        $fileName = basename(parse_url($url, PHP_URL_PATH));

        try {
            return $this->fileStorer->storeAs($path, $fileName, $tempFile, $options);
        } catch (Exception $e) {
            Log::error("Failed to store image from URL: $url to path: $path. Error: " . $e->getMessage());

            return null;
        }
    }
}
