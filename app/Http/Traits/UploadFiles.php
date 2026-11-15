<?php

namespace App\Http\Traits;

use App\Http\Traits\Packages;
use Illuminate\Support\Facades\Log;

trait UploadFiles
{
    use Packages;

    protected $allowedMimes = [
        // Images
        'image/jpeg',
        'image/png',
        'image/jpg',
        // Documents
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation'
    ];

    public function validateFile($request, $inputName)
    {
        $rules = [
            $inputName => 'required|file|mimes:jpeg,png,jpg,pdf,xlsx,xls,docx,doc,ppt,pptx|max:4096',
        ];

        $request->validate($rules);
    }

    public function checkVirusOrMalware($file)
    {
        try {
            # Implement virus scanning logic here
            return true;
        } catch (\Exception $e) {
            Log::error('Security check failed: ' . $e->getMessage());
            return false;
        }
    }

    public function uploadFile($request, $model, $collectionName, $inputName = 'file')
    {
        if (!$request->hasFile($inputName)) {
            return null;
        }

        $file = $request->file($inputName);
        if (!$file->isValid()) {
            throw new \Exception('Invalid file upload attempt');
        }

        $this->validateFile($request, $inputName);

        $mimeType = $file->getMimeType();
        if (!in_array($mimeType, $this->allowedMimes)) {
            throw new \Exception('Invalid file type');
        }

        // Convert bytes to Gigabytes (GB) to match package quota unit (GB)
        $fileSize = round($file->getSize() / (1024 * 1024 * 1024), 6);
        if (!$this->checkPackage('storage', $fileSize)) {
            $model->delete();
            throw new \App\Exceptions\InsufficientStorageException();
        }


        if (!$this->checkVirusOrMalware($file)) {
            throw new \Exception('File security check failed');
        }

        try {
            $media = $model->addMediaFromRequest($inputName)
                ->toMediaCollection($collectionName);

            $media->update([
                'created_by' => auth()->user()->id,
            ]);

            $this->setPackageUsage('storage', $fileSize);

            return $media;
        } catch (\Exception $e) {
            Log::error("Upload failed: " . $e->getMessage());
            throw $e;
        }
    }
}
