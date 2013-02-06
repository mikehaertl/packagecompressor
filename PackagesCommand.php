<?php
Yii::import('application.components.ClientScriptPackageCompressor');
/**
 * PackagesCommand
 *
 * This is a maintenance command for the ClientScriptPackageCompressor component.
 *
 * @author Michael Härtl <haertl.mike@gmail.com>
 * @version 1.1.6
 */
class PackagesCommand extends CConsoleCommand
{
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
        $clientScript=Yii::app()->clientScript;

        if($name===null)
        {
            $names=$clientScript->getCompressedPackageNames();
            echo "\nCompressed packages found: ".implode(' ',$names)."\n";
        }
        else
            $names=array($name);

        foreach($names as $name)
        {
            if(($info=$clientScript->getCompressedInfo($name))===null)
            {
                echo "No compressed data for package '$name' found\n";
                exit;
            }


            if(isset($info['js']['file']))
                echo "\nPackage '$name' contains Javascript. The compressed file is:\n\n  ".$info['js']['file']."\n";

            if(isset($info['js']['urls']))
            {
                echo "\n  It provides the following script URLs:\n\n";
                foreach($info['js']['urls'] as $k=>$v)
                    echo "    $v \n";
            }

            if(isset($info['js']['files']))
            {
                echo "\n  The files used to create the compressed file where:\n\n";
                foreach($info['js']['files'] as $k=>$v)
                    echo "    $v \n";
            }

            if(isset($info['js']['coreScripts']))
                echo "\n  Some files represent Yii core scripts or are part of sub packages:\n\n    ".
                    implode(' ',$info['js']['coreScripts'])."\n";


            if(isset($info['css']['file']))
                echo "\nPackage '$name' contains CSS. The compressed file is:\n\n  ".$info['css']['file']."\n";

            if(isset($info['css']['urls']))
            {
                echo "\n  It provides the following CSS URLs:\n\n";
                foreach($info['css']['urls'] as $k=>$v)
                    echo "    $v \n";
            }

            if(isset($info['css']['files']))
            {
                echo "\n  The files used to create the compressed file where:\n\n";
                foreach($info['css']['files'] as $k=>$v)
                    echo "    $v \n";
            }
        }
    }
}
