<?php
/*
 * The MIT License (MIT)
 *
 * Copyright (c) 2018 TwelveTone LLC
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */

namespace Grav\Plugin;

use Grav\Common\Cache;
use Grav\Common\Utils;

// Filter elements
function addFastFilter($itemSelector, $parentSelector, $parentItemSelector)
{
    return "
    <div style='padding:0 1em'>
    <input 
        class ='fast-filter' 
        onkeyup='FastFilter.element_text_filter(\"$itemSelector\", $(this).val());
        FastFilter.visible_children_filter(\"$parentSelector\", \"$parentItemSelector\");'
        type='text' style='width:100%;' \
        placeholder='Search...'>
    </input>
    </div>";
}

class CssEditorTwigExtensions extends \Twig_Extension
{
    public $grav;

    public function __construct($grav)
    {
        $this->grav = $grav;
    }

    public function getName()
    {
        return 'CssEditorTwigExtension';
    }

    public function getFunctions()
    {
        return [
            new \Twig_SimpleFunction('process_file_action', [$this, 'process_file_action']),
            new \Twig_SimpleFunction('get_file_contents', [$this, 'get_file_contents']),

            new \Twig_SimpleFunction('css_editor_list', [$this, 'css_editor_list']),
            new \Twig_SimpleFunction('css_editor_edit', [$this, 'css_editor_edit']),

            new \Twig_SimpleFunction('get_directory_list', [$this, 'get_directory_list']),
        ];
    }

    public function process_file_action()
    {
        $op = $_POST["op"];
        $path = $_POST["target"];

        if (!$this->startsWith($path, "user/")) {
            http_response_code(403); // Forbidden
            die;
        }
        if (Utils::contains($path, "..")) {
            http_response_code(400);
            die;
        }
        if ($op === 'create') {
            if (is_file($path)) {
                http_response_code(409); // Conflict
                die;
            }
        } else {
            if (!is_file($path)) {
                http_response_code(404); // Not Found
                die;
            }
        }

        switch ($op) {
            case 'save':
                $value = $_POST["value"];
                //$current = file_get_contents($path);
                if (file_put_contents($path, $value) != false) {
                    $this->grav['log']->info("CSS file updated: $path");
                    Cache::clearCache('standard');
                    return "{\"error\":null}";
                } else {
                    http_response_code(500);
                    exit();
                }
                break;

            case 'delete':
                unlink($path);
                die("{\"error\":null, \"newTarget\":null}");

            case 'create':
                // value must be in user or system folder
                $rx = '#^(user|system)/.*#';
                if (!preg_match($rx, $path)) {
                    http_response_code(403);
                    die;
                }
                touch($path);
                die("{\"error\":null, \"newTarget\":\"$path\"}");

            case "rename":
                $value = $_POST["value"];

                if (Utils::contains($value, "/")) {
                    http_response_code(400);
                    die;
                }
                if (Utils::contains($value, "..")) {
                    http_response_code(400);
                    die;
                }
                $newPath = dirname($path) . '/' . $value;
                if (file_exists($newPath)) {
                    http_response_code(409);
                    die;
                }
                rename($path, $newPath);
                die("{\"error\":null, \"newTarget\":\"$newPath\"}");

            case "move":
            case "copy":
                $value = $_POST["value"];

                if (Utils::startsWith($value, "/")) {
                    http_response_code(400);
                    die;
                }
                if (Utils::startsWith($value, ".")) {
                    http_response_code(400);
                    die;
                }
                $newPath = $value;
                if (file_exists($newPath)) {
                    http_response_code(409);
                    die;
                }
                // Create parent directory
                if (!is_dir(dirname($newPath))) {
                    mkdir(dirname($newPath));
                }
                if ($op === 'move') {
                    rename($path, $newPath);
                } else {
                    copy($path, $newPath);
                }
                die("{\"error\":null, \"newTarget\":\"$newPath\"}");

            default:
                http_response_code(405);
                exit();
                break;
        }
    }

    private function startsWith($string, $search)
    {
        if ($string == null) {
            return false;
        }
        return (strncmp($string, $search, strlen($search))) == 0;
    }

    private function getCssDirectories()
    {
        $theDirs = array();
        foreach (glob("user/themes/*", GLOB_ONLYDIR) as $dir) {
            foreach (scandir($dir) as $item) {
                if (!is_dir("$dir/$item")) continue;
                switch ($item) {
                    //TODO add more allowable folder types
                    case "css":
                    case "scss":
                    case "css-compiled":
                        array_push($theDirs, "$dir/$item");
                        break;
                    default:
                        break;
                }
            }
        }
        $rx = '#\.(css|scss)$#';
        foreach (glob("user/plugins/*", GLOB_ONLYDIR) as $dir) {
            foreach (scandir($dir) as $item) {
                if (!is_dir("$dir/$item")) continue;
                //array_push($theDirs, "$dir/$item");
                foreach (scandir("$dir/$item") as $f) {
                    if (preg_match($rx, $f)) {
                        array_push($theDirs, "$dir/$item");
                        break;
                    }
                }
            }
        }
        return $theDirs;
    }

    private function isValidCssExtension($path)
    {
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        switch ($ext) {
            case "css":
            case "scss":
                return true;
                break;
            default:
                return false;
        }
    }

    private function getFiles($path)
    {
        $theFiles = array();
        foreach (scandir($path) as $item) {
            if ($this->isValidCssExtension($item)) {
                array_push($theFiles, $item);
            }
        }
        return $theFiles;
    }

    
    public function get_directory_list($directory)
    {
        // Starts the HTML elements
        $s = "";
        $s .= addFastFilter(".editor-items a", ".editor-items .editor-section", "a");
        $s .= "<div class='editor-items'><div class='editor-section'>";

        // If directory empty then show user and system
        if ($directory=="") {
            $systemUrl = $this->encodeDirectoryUrl('system');
            $s .= "<a href='$systemUrl'}><div class='editor-file'>system/</div></a>";
            $userUrl = $this->encodeDirectoryUrl('user');
            $s .= "<a href='$userUrl'}><div class='editor-file'>user/</div></a>";
            $s .= '</div></div>';
            return $s;
        }

        // Check if directory is valid
        if (!is_dir($directory)) {
            return "<div>$directory is not a directory.</div>";
        }

        // Disallow use of .. to prevent access to restricted directories
        if (preg_match("/(\/\.\.|\/\.)/",$directory)) {
            return "<div>The use of special links . and .. are not allowed.</div>";
        }

        // We can assume $directory is indeed a valid directory since this check is done in the route
        $directoryList = scandir($directory);
        foreach($directoryList as $child){
            $path = "$directory/$child";
            $url = "";
            if (is_dir($path)){
                $url = $this->encodeDirectoryUrl($path);
                $child .= "/";
            } elseif (is_file($path)) {
                $url = $this->encodeFileUrl($path,pathinfo($path, PATHINFO_EXTENSION));
            }
            $s .= "<a href='$url'}><div class='editor-file'>$child</div></a>";
        }

        // Ends HTML elements and returns it
        $s .= '</div></div>';
        return $s;
    }

    private function get_extension_editor_directories($theExtension)
    {
//        $theDirectories = Grav::instance()['twig']->twig_paths;
        $theDirectories[] = 'user/plugins';
        $theDirectories[] = 'user/themes';
        $theDirectories[] = 'user/config';
        $theDirectories[] = 'system';

        $s = "";
        $s .= addFastFilter(".editor-items a", ".editor-items .editor-section", "a");
        $s .= "<div class='editor-items'>";
        foreach ($theDirectories as $dir1) {
            self::walkDir($dir1, function ($dir) use (&$s, $theExtension) {
                $shortDir = preg_replace("#^.*?/(user|system)/#", "$1/", $dir);
                $s .= "<div class='editor-section'><div class='editor-folder'>$shortDir</div>";
                foreach (scandir($dir) as $file) {
                    $path = "$dir/$file";
                    if (is_dir($path)) {
                        continue;
                    }
                    if (!is_file($path)) continue;
                    if (pathinfo($path, PATHINFO_EXTENSION) !== $theExtension) continue;
                    $editorUrl = $this->getEditorUrl("$shortDir/$file", $theExtension);
                    $s .= "<a href='$editorUrl'}><div class='editor-file'>$file</div></a>";
                }
                $s .= '</div>';
            });
        }
        $s .= '</div>';
        return $s;
    }

    private function encodeFileUrl($path, $extension)
    {
        $xpath = urlencode($path);
        return "edit?language=$extension&target=$xpath";
    }

    private function encodeDirectoryUrl($path)
    {
        $xpath = urlencode($path);
        return "directory?target=$xpath";
    }

    public function css_editor_list()
    {
        $theDirs = $this->getCssDirectories();

        $text = "";
        $text .= "<button class='fast-filter' onclick='FastFilter.element_text_filter(\".editor-items h3\", \"auto\");'>Search</button>\n";
        $text .= "<div class='editor'>";
        foreach ($theDirs as $dir) {
            $text .= "<h2>$dir</h2>";
            foreach ($this->getFiles($dir) as $file) {
                $path = "$dir/$file";
                try {
                    $css = file_get_contents($path);
                    if (strlen($css) < 5000) {
                        $url = $this->getEditorUrl($path, "css");
                        $text .= "<h3 style='text-align:left'>$file</h3>";
                        $text .= "<button onclick='window.location=(\"$url\")'>Edit $file</button>";
                        $text .= "<pre>" . htmlspecialchars($css) . "</pre>";
                        $text .= "<button onclick='window.location=(\"$url\")'>Edit $file</button>";
                    }
                } catch (Exception $e) {
                }
            }
        }
        $text .= "</div>"; // CSS plugin

        return $text;
    }

    public function get_file_contents($pageToEdit)
    {
        $contents = file_get_contents($pageToEdit);
        // Output is escaped in twig
        //$xcss = htmlspecialchars($contents);
        return $contents;
    }
}
