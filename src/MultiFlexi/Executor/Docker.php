<?php

declare(strict_types=1);

/**
 * This file is part of the MultiFlexi package
 *
 * https://multiflexi.eu/
 *
 * (c) Vítězslav Dvořák <http://vitexsoftware.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace MultiFlexi\Executor;

/**
 * Description of Docker.
 */
class Docker extends Native implements \MultiFlexi\executor
{
    use \Ease\Logger\Logging;
    public const PULLCOMMAND = 'docker pull docker.io/vitexsoftware/debian:bookworm';

    public function __construct(\MultiFlexi\Job &$job)
    {
        parent::__construct($job);
    }

    public static function name(): string
    {
        return _('Docker');
    }

    /**
     * {@inheritDoc}
     */
    public static function description(): string
    {
        return _('Execute jobs in container using Docker');
    }

    /**
     * @see https://docker-php.readthedocs.io/en/latest/cookbook/container-run/
     */
    public function cmdparams()
    {
        file_put_contents($this->envFile(), $this->job->envFile());

        // Build the entrypoint command manually to avoid recursion
        $entrypoint = $this->job->application->getDataValue('executable');
        $params = $this->job->getCmdParams();
        $ociimage = $this->job->application->getDataValue('ociimage');

        return 'run --env-file '.$this->envFile().' --entrypoint '.$entrypoint.' '.$ociimage.' '.$params;
    }

    public function envFile()
    {
        return sys_get_temp_dir().'/'.$this->job->getMyKey().'.env';
    }

    /**
     * {@inheritDoc}
     */
    public function executable()
    {
        return 'docker';
    }

    /**
     * Can this Executor execute given application ?
     *
     * @param \MultiFlexi\Application $app
     */
    public static function usableForApp($app): bool
    {
        return empty($app->getDataValue('ociimage')) === false; // Container Image must be present
    }

    public static function logo(): string
    {
        return 'data:image/svg+xml;base64,PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz'
                .'0iVVRGLTgiIHN0YW5kYWxvbmU9Im5vIj8+CjwhLS0gVXBsb2FkZWQgdG86IFNWRyBSZXBvL'
                .'CB3d3cuc3ZncmVwby5jb20sIEdlbmVyYXRvcjogU1ZHIFJlcG8gTWl4ZXIgVG9vbHMgLS0+';
    }
}
