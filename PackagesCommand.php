<?php
Yii::import('application.components.ClientScriptPackageCompressor');
/**
 * PackagesCommand
 *
 * This is a maintenance command for the ClientScriptPackageCompressor component.
 *
 * @author Michael Härtl <haertl.mike@gmail.com>
 * @version 1.0.2
 */
class PackagesCommand extends CConsoleCommand
{
    const COLOR_PACKAGE = 'red';
    const COLOR_FILE    = 'green';
    const COLOR_URL     = 'green';
    const COLOR_SOURCE  = 'dark_gray';

    public function actionIndex()
    {
        echo <<<EOD
This is the maintenance command for the ClientScriptPackageCompressor
component. Usage:

    ./yiic packages <command> [options]

Available commands are:

    compress --name=<name>

        Compress package <name>.

    reset [options]

        Resets all compressed packages. If no package name is specified, all
        packages will be reset. To create the compressed packages either
        call the "compress" command or let it happen automatically on next
        request.

    info [--name=<name>]

        Output some debug information about package <name>. If <name> is
        omitted, debug data for all packages is displayed.

Options:

    --name=<name>
        Name of the package to compress or reset

    --quiet
        Supress any output of this script


EOD;
    }

    /**
     * Reset the compression status of a package
     *
     * @param string $name name of package to reset
     * @param bool $quiet suppress any output
     */
    public function actionReset($name=null,$quiet=false)
    {
        $clientScript=Yii::app()->clientScript;
        $result=$clientScript->resetCompressedPackage($name);
        if (!$quiet)
        {
            if(!$result)
                echo "Nothing to do.\n";
            elseif($name===null)
                echo "All packages reset.\n";
            else
                echo "Package '$name' reset.\n";
        }
    }

    /**
     * Compress a package
     *
     * @param string $name name of package to compress
     */
    public function actionCompress($name)
    {
        $clientScript=Yii::app()->clientScript;

        // We need to publish core scripts manually. Otherwhise Yii would try to do
        // this automatically and break, because the code uses Yii::app()->getAssetManager()
        // which is not available in console apps (should be Yii::app()->assetManager):
        $clientScript->coreScriptUrl=Yii::app()->assetManager->publish(YII_PATH.'/web/js/source');

        $clientScript->compressPackage($name);
    }

    /**
     * Output debug information about one or all packages
     *
     * @param string $name the package name. Shows all if empty.
     */
    public function actionInfo($name=null)
    {
        $clientScript   = Yii::app()->clientScript;
        $colors         = new ConsoleColors;


        if($name===null)
        {
            echo "-------------------------------------------------------\n";
            $names = $clientScript->getCompressedPackageNames();
            echo "Compressed packages found: ".$colors->getColoredString(implode(' ',$names),self::COLOR_PACKAGE)."\n";
        }
        else
            $names=array($name);

        echo "-------------------------------------------------------\n";

        foreach($names as $name)
        {
            if(($info=$clientScript->getCompressedInfo($name))===null)
            {
                echo "No compressed data for package '".$colors->getColoredString($name,self::COLOR_PACKAGE)."' found\n";
                exit;
            }


            if(isset($info['js']['file'])) {
                echo "Package '".$colors->getColoredString($name,self::COLOR_PACKAGE)."' contains Javascript.\n\n";
                echo "  The compressed file is:\n\n    ".$colors->getColoredString($info['js']['file'],self::COLOR_FILE)."\n";
            }

            if(isset($info['js']['urls']))
            {
                echo "\n  It provides the following script URLs:\n\n";
                foreach($info['js']['urls'] as $k=>$v)
                    echo $colors->getColoredString("    $v \n",self::COLOR_URL);
            }

            if(isset($info['js']['files']))
            {
                echo "\n  The files used to create the compressed file where:\n\n";
                foreach($info['js']['files'] as $k=>$v)
                    echo $colors->getColoredString("    $v \n",self::COLOR_SOURCE);
            }

            if(isset($info['js']['coreScripts']))
                echo "\n  Some files represent Yii core scripts or are part of sub packages:\n\n    ".
                    $colors->getColoredString(implode(' ',$info['js']['coreScripts']),self::COLOR_PACKAGE)."\n";


            if(isset($info['css']['file'])) {
                echo "\nPackage '".$colors->getColoredString($name, self::COLOR_PACKAGE)."' contains CSS.\n\n";
                echo "  The compressed file is:\n\n    ".$colors->getColoredString($info['css']['file'],self::COLOR_FILE)."\n";
            }

            if(isset($info['css']['urls']))
            {
                echo "\n  It provides the following CSS URLs:\n\n";
                foreach($info['css']['urls'] as $k=>$v)
                    echo $colors->getColoredString("    $v \n",self::COLOR_URL);
            }

            if(isset($info['css']['files']))
            {
                echo "\n  The files used to create the compressed file where:\n\n";
                foreach($info['css']['files'] as $k=>$v)
                    echo $colors->getColoredString("    $v \n",self::COLOR_SOURCE);
            }
            echo "-------------------------------------------------------\n";
        }
    }
}

// Credits: This class was found here:
// http://www.if-not-true-then-false.com/2010/php-class-for-coloring-php-command-line-cli-scripts-output-php-output-colorizing-using-bash-shell-colors/
class ConsoleColors {
    private $foreground_colors = array();
    private $background_colors = array();

    public function __construct() {
        // Set up shell colors
        $this->foreground_colors['black'] = '0;30';
        $this->foreground_colors['dark_gray'] = '1;30';
        $this->foreground_colors['blue'] = '0;34';
        $this->foreground_colors['light_blue'] = '1;34';
        $this->foreground_colors['green'] = '0;32';
        $this->foreground_colors['light_green'] = '1;32';
        $this->foreground_colors['cyan'] = '0;36';
        $this->foreground_colors['light_cyan'] = '1;36';
        $this->foreground_colors['red'] = '0;31';
        $this->foreground_colors['light_red'] = '1;31';
        $this->foreground_colors['purple'] = '0;35';
        $this->foreground_colors['light_purple'] = '1;35';
        $this->foreground_colors['brown'] = '0;33';
        $this->foreground_colors['yellow'] = '1;33';
        $this->foreground_colors['light_gray'] = '0;37';
        $this->foreground_colors['white'] = '1;37';

        $this->background_colors['black'] = '40';
        $this->background_colors['red'] = '41';
        $this->background_colors['green'] = '42';
        $this->background_colors['yellow'] = '43';
        $this->background_colors['blue'] = '44';
        $this->background_colors['magenta'] = '45';
        $this->background_colors['cyan'] = '46';
        $this->background_colors['light_gray'] = '47';
    }

    // Returns colored string
    public function getColoredString($string, $foreground_color = null, $background_color = null) {
        $colored_string = "";

        // Check if given foreground color found
        if (isset($this->foreground_colors[$foreground_color])) {
            $colored_string .= "\033[" . $this->foreground_colors[$foreground_color] . "m";
        }
        // Check if given background color found
        if (isset($this->background_colors[$background_color])) {
            $colored_string .= "\033[" . $this->background_colors[$background_color] . "m";
        }

        // Add string and end coloring
        $colored_string .=  $string . "\033[0m";

        return $colored_string;
    }

    // Returns all foreground color names
    public function getForegroundColors() {
        return array_keys($this->foreground_colors);
    }

    // Returns all background color names
    public function getBackgroundColors() {
        return array_keys($this->background_colors);
    }
}
?>
