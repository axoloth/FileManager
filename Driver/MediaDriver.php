<?php

namespace Youwe\MediaBundle\Driver;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Security\Core\SecurityContext;

/**
 * Class MediaDriver
 * @package Youwe\MediaBundle\Driver
 */
class MediaDriver
{
    /**
     * Contains all mime types that are allowed to upload.
     * @var array
     */
    public $mime_allowed;

    /**
     * The path to the upload directory
     * @var string
     */
    public $upload_path;

    /**
     * The class where the function is defined for getting
     * the usages amount of the media item
     *
     * This class should have the function returnUsage();
     * @var
     */
    public $usage_class;

    /**
     * @param ContainerInterface $container
     */
    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;

        $parameters = $this->container->getParameter('youwe_media');
        $this->upload_path = $parameters['upload_path'];
        $this->mime_allowed = $parameters['mime_allowed'];
    }


    /**
     * @param $files
     * @param $dir
     * @return string
     * @throws \Exception
     */
    public function handleFiles($files, $dir){
        /** @var UploadedFile $file */
        foreach($files as $file){

            $extension = $file->guessExtension();
            if (!$extension) {
                $extension = 'bin';
            }

            if(in_array($file->getClientMimeType(), $this->mime_allowed)){

                $original_file = $file->getClientOriginalName();
                $path_parts = pathinfo($original_file);

                $increment = '';
                while(file_exists($dir . "/" . $path_parts['filename'] . $increment . '.' . $extension)) {
                    $increment++;
                }

                $basename = $path_parts['filename'] . $increment . '.' . $extension;
                $file->move($dir,$basename);
            } else {
                throw new \Exception("Mimetype is not allowed", 500);
            }
        }
        return true;
    }

    /**
     * @param $dir
     * @param $dir_name
     * @throws \Exception
     * @return bool
     */
    public function makeDir($dir, $dir_name){
        $fm = new Filesystem();
        $dir_path = rtrim($dir,"/") . "/" . $dir_name;
        if(!file_exists($dir_path)){
            $fm->mkdir($dir_path, 0700);
        } else {
            throw new \Exception("Cannot create directory '" . $dir_name ."': Directory already exists", 500);
        }
    }

    /**
     * @param $dir
     * @param $file_name
     * @param $new_file_name
     * @throws \Exception
     * @return bool
     */
    public function renameFile($dir, $file_name, $new_file_name){
        try{
            $this->validateFile($dir, $file_name, $new_file_name);
            $fm = new Filesystem();
            $old_file = rtrim($dir,"/") . "/" . $file_name;
            $new_file = rtrim($dir,"/") . "/" . $new_file_name;
            $fm->rename($old_file, $new_file);
        } catch(\Exception $e){
            throw new \Exception("Cannot rename file or directory");
        }
    }

    /**
     * @param $dir
     * @param $file_name
     * @param $new_file_name
     * @throws \Exception
     * @return bool
     */
    public function moveFile($dir, $file_name, $new_file_name){
        try{
            $this->validateFile($dir, $file_name);
            $file_path = rtrim($dir,"/") . "/" . $file_name;
            $file = new File($file_path, false);
            $file->move($new_file_name);
        } catch(\Exception $e){
            throw new \Exception("Cannot move file or directory");
        }
    }

    /**
     * @param $dir
     * @param $file_name
     * @throws \Exception
     * @return bool
     */
    public function deleteFile($dir, $file_name){
        try{
            $fm = new Filesystem();
            $file = rtrim($dir,"/") . "/" . $file_name;
            $fm->remove($file);
        } catch(\Exception $e){
            throw new \Exception("Cannot delete file or directory");
        }
    }

    /**
     * @param $dir
     * @param $zip_file
     * @throws \Exception
     * @return bool
     */
    public function extractZip($dir, $zip_file){
        $chapterZip = new \ZipArchive ();

        $fm = new Filesystem();
        $tmp_dir = $this->createTmpDir($fm);

        if ($chapterZip->open ( $dir . "/" . $zip_file )) {
            $chapterZip->extractTo($tmp_dir);
            $chapterZip->close();
        }

        $this->checkFileType($fm, $tmp_dir);

        try{
            if ($chapterZip->open ( $dir . "/" . $zip_file )) {

                $chapterZip->extractTo($dir);
                $chapterZip->close();
            }
        } catch(\Exception $e){
            throw new \Exception("Cannot extract zip");
        }
    }

    /**
     * Validate the files to check if they have a valid filetype
     * @param      $dir
     * @param      $filename
     * @param null $new_filename
     * @throws \Exception
     */
    public function validateFile($dir, $filename, $new_filename = null){
        $file_path = $dir . DIRECTORY_SEPARATOR . $filename;
        if(!is_dir($file_path)){
            $fm = new Filesystem();
            $tmp_dir = $this->createTmpDir($fm);
            $fm->copy($file_path, $tmp_dir . DIRECTORY_SEPARATOR . $filename);
            if(!is_null($new_filename)){
                $fm->rename($tmp_dir . DIRECTORY_SEPARATOR . $filename, $tmp_dir . DIRECTORY_SEPARATOR . $new_filename);
            }
            $this->checkFileType($fm, $tmp_dir);
        }
    }

    /**
     * @param Filesystem $fm
     * @return string
     */
    public function createTmpDir(Filesystem $fm){
        $tmp_dir = $this->upload_path . "/" . "." . strtotime("now");
        $fm->mkdir($tmp_dir);
        return $tmp_dir;
    }

    /**
     * Check if the filetype is an valid filetype
     *
     * @param Filesystem $fm
     * @param            $tmp_dir
     * @throws \Exception
     */
    public function checkFileType(Filesystem $fm, $tmp_dir){
        $di = new \RecursiveDirectoryIterator($tmp_dir);
        foreach (new \RecursiveIteratorIterator($di) as $filename => $file) {
            $mime_valid = $this->checkMimeType($filename);
            if($mime_valid !== true){
                $fm->remove($tmp_dir);
                throw new \Exception($mime_valid, 500);
            }
        }
        $fm->remove($tmp_dir);
    }

    /**
     * @param $name
     * @return false
     */
    public function checkMimeType($name){
        $mime = mime_content_type($name);

        if($mime !=  'directory'){
            if (!in_array($mime, $this->mime_allowed)) {
                return 'Mime type "'.mime_content_type($name).'" not allowed for file "'. basename($name) .'"';
            }
            return true;
        }
        else {
            return true;
        }
    }
}
