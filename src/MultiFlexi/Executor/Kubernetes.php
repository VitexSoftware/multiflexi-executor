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

use Symfony\Component\Process\Process;

/**
 * Execute jobs using Kubernetes (kubectl).
 *
 * Flow:
 * 1. Optional Helm pre-deploy when the application has a helmchart configured
 * 2. One-shot pod via `kubectl run --restart=Never` (--attach or hold-for-cp)
 * 3. Optional artifact collection via `kubectl cp` for every path pattern in
 *    application.json `artifacts` / `app_artifacts` into host MULTIFLEXI_TMP
 * 4. Pod cleanup (unless keepPodOnFailure and the job failed)
 *
 * Namespace resolution order:
 *   MULTIFLEXI_K8S_NAMESPACE env → Helm chart namespace → cluster default
 */
class Kubernetes extends Native implements \MultiFlexi\executor
{
    private ?Process $process = null;
    private ?string $podName = null;
    private ?string $kubeconfig = null;
    private string $jobStdout = '';
    private string $jobStderr = '';
    private ?int $jobExitCode = null;
    /** @var array<string, string> Env field code → original host path remapped for the pod */
    private array $remappedHostPaths = [];

    private const CONTAINER_TMP = '/tmp';

    /**
     * Store pod logs after job completion via `kubectl logs`.
     */
    public function storeLogs(): void
    {
        if ($this->podName === null || $this->kubeconfig === null) {
            return;
        }

        $kubernetes = $this->kubernetesConfig();
        $helmConfig = \is_array($kubernetes['helm'] ?? null) ? $kubernetes['helm'] : [];
        $namespace = $this->resolveNamespace($helmConfig);
        $this->capturePodLogs($namespace, $this->podName);
    }

    public static function description(): string
    {
        return _('Execute jobs in container using Kubernetes');
    }

    public static function logo(): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode(<<<'MULTIFLEXI_EXECUTOR_LOGO'
<svg width="115" height="111" viewBox="0 0 115 111" fill="none" version="1.1" id="svg2" xmlns="http://www.w3.org/2000/svg" xmlns:svg="http://www.w3.org/2000/svg"><g clip-path="url(#clip0_211_1892)" id="g2" transform="matrix(0.98677347,0,0,0.98677347,0.75654701,0.73407305)"><path d="M 56.8026,0.00978411 C 55.7913,0.0607293 54.8004,0.311475 53.8881,0.747311 L 14.1176,19.7501 c -1.0293,0.4916 -1.9338,1.2069 -2.6462,2.0927 -0.7123,0.8859 -1.2143,1.9195 -1.4687,3.0242 L 0.19107,67.552 c -0.226565,0.9835 -0.2519209,2.002 -0.074566,2.9954 0.177354,0.9934 0.553816,1.9413 1.107106,2.7878 0.13416,0.207 0.27844,0.4073 0.43234,0.6002 L 29.1835,108.162 c 0.7126,0.885 1.6171,1.6 2.6465,2.092 1.0294,0.491 2.1571,0.746 3.2995,0.746 l 44.1448,-0.01 c 1.142,0.001 2.2695,-0.254 3.2989,-0.744 1.0293,-0.491 1.9341,-1.205 2.6471,-2.089 L 112.738,73.9253 c 0.713,-0.8861 1.215,-1.9201 1.469,-3.0253 0.255,-1.1051 0.255,-2.253 0.001,-3.3582 l -9.827,-42.685 c -0.255,-1.1047 -0.757,-2.1383 -1.469,-3.0242 -0.712,-0.8858 -1.617,-1.6011 -2.646,-2.0927 L 60.4903,0.747311 C 59.3427,0.199064 58.0747,-0.0545367 56.8026,0.00978411 Z" fill="#326ce5" id="path1" /><path d="m 57.1967,14.5361 c -1.3146,10e-5 -2.3806,1.1842 -2.3804,2.6449 0,0.0224 0.0046,0.0438 0.0051,0.0661 -0.002,0.1985 -0.0116,0.4376 -0.0051,0.6104 0.0313,0.8425 0.215,1.4872 0.3255,2.2634 0.2003,1.6613 0.3681,3.0384 0.2645,4.3184 -0.1007,0.4826 -0.4562,0.9241 -0.7731,1.2309 l -0.056,1.0071 c -1.4285,0.1184 -2.8666,0.3351 -4.3031,0.6612 -6.1809,1.4034 -11.5026,4.5872 -15.5542,8.886 -0.2629,-0.1794 -0.7228,-0.5094 -0.8596,-0.6104 -0.425,0.0574 -0.8545,0.1885 -1.414,-0.1373 -1.0653,-0.7171 -2.0355,-1.7069 -3.2095,-2.8993 -0.5379,-0.5703 -0.9275,-1.1135 -1.5666,-1.6632 -0.1451,-0.1249 -0.3666,-0.2938 -0.529,-0.4222 -0.4997,-0.3984 -1.089,-0.6061 -1.6582,-0.6256 -0.7317,-0.0251 -1.4362,0.261 -1.8972,0.8392 -0.8195,1.0279 -0.5571,2.5991 0.585,3.5096 0.0115,0.0093 0.0239,0.0164 0.0356,0.0255 0.1569,0.1272 0.3491,0.2902 0.4933,0.3967 0.6783,0.5008 1.2978,0.7571 1.9736,1.1546 1.4237,0.8792 2.6039,1.6083 3.5401,2.4873 0.3656,0.3896 0.4295,1.0763 0.4781,1.3733 l 0.763,0.6816 C 27.3702,46.4809 25.4799,54.0731 26.597,61.809 L 25.6,62.0989 c -0.2627,0.3393 -0.634,0.8732 -1.0223,1.0326 -1.2249,0.3858 -2.6033,0.5274 -4.2675,0.7019 -0.7813,0.065 -1.4555,0.0262 -2.2838,0.1831 -0.1823,0.0346 -0.4363,0.1007 -0.6358,0.1475 -0.0069,0.0015 -0.0134,0.0035 -0.0204,0.0051 -0.0108,0.0025 -0.0251,0.0078 -0.0356,0.0102 -1.403,0.339 -2.3043,1.6286 -2.0142,2.8992 0.2903,1.2709 1.6607,2.0438 3.0722,1.7396 0.0102,-0.0024 0.025,-0.0028 0.0356,-0.0051 0.0159,-0.0037 0.03,-0.0114 0.0458,-0.0153 0.1968,-0.0432 0.4433,-0.0912 0.6154,-0.1373 0.8144,-0.218 1.4042,-0.5384 2.1363,-0.8189 1.5751,-0.5649 2.8796,-1.0369 4.1505,-1.2207 0.5308,-0.0416 1.0901,0.3275 1.3683,0.4832 l 1.0376,-0.1781 c 2.3878,7.403 7.3918,13.3866 13.7282,17.1412 l -0.4324,1.0376 c 0.1559,0.4029 0.3277,0.9481 0.2117,1.346 -0.4621,1.1981 -1.2535,2.4628 -2.1547,3.8727 -0.4363,0.6513 -0.8829,1.1568 -1.2767,1.9023 -0.0942,0.1783 -0.2142,0.4523 -0.3051,0.6409 -0.6118,1.309 -0.1631,2.8166 1.0122,3.3824 1.1826,0.5693 2.6505,-0.0311 3.2858,-1.3428 9e-4,-0.0019 0.0041,-0.0032 0.005,-0.0051 7e-4,-0.0015 -6e-4,-0.0036 0,-0.0051 0.0905,-0.1859 0.2187,-0.4304 0.2951,-0.6052 0.3372,-0.7727 0.4494,-1.4348 0.6866,-2.1821 0.6299,-1.5821 0.9759,-3.2421 1.8429,-4.2765 0.2375,-0.2832 0.6245,-0.3922 1.0258,-0.4996 l 0.5392,-0.9766 c 5.5239,2.1203 11.7071,2.6893 17.8838,1.2868 1.409,-0.3199 2.7693,-0.7339 4.0843,-1.2309 0.1516,0.2688 0.4332,0.7855 0.5087,0.9156 0.4078,0.1327 0.8529,0.2012 1.2156,0.7375 0.6487,1.1083 1.0923,2.4194 1.6328,4.003 0.2372,0.7473 0.3544,1.4094 0.6917,2.1821 0.0769,0.1761 0.2044,0.4239 0.295,0.6103 0.634,1.316 2.1065,1.9185 3.2909,1.3479 1.1751,-0.5661 1.6244,-2.0736 1.0122,-3.3824 -0.091,-0.1885 -0.216,-0.4625 -0.3103,-0.6409 -0.3938,-0.7454 -0.8403,-1.2459 -1.2766,-1.8972 -0.9013,-1.4099 -1.6488,-2.5811 -2.1109,-3.7792 -0.1932,-0.618 0.0326,-1.0023 0.1831,-1.4039 -0.0901,-0.1033 -0.283,-0.6869 -0.3967,-0.9613 6.585,-3.8881 11.4421,-10.0949 13.7231,-17.2632 0.308,0.0484 0.8433,0.1431 1.0172,0.178 0.3581,-0.2361 0.6872,-0.5442 1.3327,-0.4934 1.2709,0.1838 2.5754,0.6559 4.1505,1.2208 0.7321,0.2804 1.3219,0.6059 2.1363,0.824 0.1721,0.046 0.4187,0.089 0.6154,0.1322 0.0158,0.0039 0.0299,0.0116 0.0458,0.0152 0.0106,0.0024 0.0254,0.0028 0.0356,0.0051 1.4116,0.3039 2.7823,-0.4685 3.0722,-1.7395 0.2898,-1.2707 -0.6111,-2.5606 -2.0142,-2.8993 -0.2041,-0.0464 -0.4936,-0.1252 -0.6918,-0.1627 -0.8283,-0.1569 -1.5024,-0.1182 -2.2838,-0.1831 -1.6642,-0.1744 -3.0426,-0.3162 -4.2675,-0.702 -0.4994,-0.1937 -0.8546,-0.7879 -1.0274,-1.0325 l -0.9613,-0.2797 c 0.4984,-3.606 0.364,-7.3589 -0.4985,-11.1138 -0.8705,-3.7899 -2.409,-7.2562 -4.4608,-10.3101 0.2466,-0.2242 0.7123,-0.6366 0.8444,-0.7579 0.0386,-0.4271 0.0054,-0.875 0.4476,-1.3479 0.9361,-0.8791 2.1164,-1.608 3.5401,-2.4873 0.6757,-0.3975 1.3004,-0.6538 1.9786,-1.1546 0.1534,-0.1132 0.3628,-0.2926 0.5239,-0.4221 1.1419,-0.9109 1.4048,-2.4819 0.5849,-3.5097 -0.8198,-1.0277 -2.4084,-1.1245 -3.5503,-0.2136 -0.1625,0.1287 -0.383,0.2967 -0.529,0.4222 -0.639,0.5498 -1.0337,1.0929 -1.5716,1.6632 -1.174,1.1924 -2.1443,2.1872 -3.2096,2.9044 -0.4616,0.2687 -1.1377,0.1757 -1.4445,0.1576 l -0.9054,0.646 C 74.4192,30.8261 67.3901,27.3649 59.8213,26.6926 59.8001,26.3754 59.7724,25.802 59.7654,25.6295 59.4555,25.333 59.0812,25.0799 58.9871,24.4393 c -0.1035,-1.28 0.0694,-2.6571 0.2696,-4.3184 0.1105,-0.7762 0.2942,-1.4209 0.3255,-2.2634 0.0072,-0.1915 -0.0043,-0.4694 -0.005,-0.6765 -2e-4,-1.4607 -1.0658,-2.6451 -2.3805,-2.6449 z m -2.9806,18.4636 -0.707,12.4871 -0.0509,0.0254 c -0.0474,1.1171 -0.9668,2.0091 -2.0956,2.0091 -0.4624,0 -0.8891,-0.1484 -1.236,-0.4018 l -0.0203,0.0102 -10.2389,-7.2583 c 3.1468,-3.0943 7.1719,-5.3811 11.8106,-6.4343 0.8473,-0.1924 1.6943,-0.3351 2.5381,-0.4374 z m 5.9663,0 c 5.4158,0.6661 10.4243,3.1184 14.2623,6.8768 L 64.2719,47.089 64.2363,47.0737 c -0.9029,0.6595 -2.1751,0.4959 -2.8789,-0.3865 -0.2883,-0.3615 -0.4396,-0.7866 -0.4578,-1.2157 l -0.0102,-0.005 z m -24.0281,11.5359 9.3488,8.3621 -0.0102,0.0508 c 0.8439,0.7336 0.9683,2.0066 0.2645,2.8891 -0.2883,0.3615 -0.6742,0.604 -1.0885,0.7172 l -0.0101,0.0407 -11.9836,3.4587 c -0.6099,-5.5771 0.7045,-10.9986 3.4791,-15.5186 z m 42.0187,0.0051 c 1.3891,2.2515 2.4409,4.7661 3.0671,7.4923 0.6187,2.6934 0.774,5.3821 0.5188,7.9805 L 69.7143,56.5446 69.7042,56.4937 c -1.0786,-0.2947 -1.7414,-1.3919 -1.4903,-2.4923 0.1028,-0.4508 0.3421,-0.8322 0.6663,-1.1139 L 68.8751,52.862 Z m -22.8938,9.0029 h 3.83 l 2.3804,2.9756 -0.8545,3.713 -3.4384,1.6531 -3.4486,-1.6582 -0.8545,-3.713 z m 12.2785,10.183 c 0.1628,-0.0082 0.3248,0.0064 0.4832,0.0356 l 0.0204,-0.0254 12.3955,2.0956 c -1.8141,5.0966 -5.2854,9.5119 -9.9235,12.4667 l -4.8118,-11.6224 0.0153,-0.0204 c -0.442,-1.027 3e-4,-2.2314 1.0173,-2.7212 0.2603,-0.1254 0.5323,-0.1948 0.8036,-0.2085 z m -20.8186,0.0508 c 0.9459,0.0133 1.7944,0.6699 2.0142,1.6328 0.1029,0.4508 0.0528,0.8974 -0.117,1.2919 l 0.0356,0.0458 -4.7608,11.5054 C 39.4599,75.397 35.9146,71.1203 34.018,65.8731 l 12.2888,-2.0855 0.0203,0.0255 c 0.1375,-0.0253 0.2769,-0.0375 0.412,-0.0357 z m 10.3813,5.0407 c 0.3295,-0.0121 0.6639,0.0555 0.9817,0.2085 0.4166,0.2006 0.7384,0.5165 0.941,0.8952 h 0.0458 l 6.0579,10.9459 c -0.7862,0.2636 -1.5945,0.4888 -2.4212,0.6765 -4.633,1.052 -9.2513,0.7332 -13.4331,-0.6917 l 6.0426,-10.9256 h 0.0102 c 0.3626,-0.6778 1.0502,-1.0822 1.7751,-1.1088 z" fill="#ffffff" stroke="#ffffff" stroke-width="0.25" id="path2" /></g><g id="g249" transform="matrix(0.15204031,0,0,0.15204031,-57.571271,-15.644872)" style="fill:#ffd42a;fill-opacity:1;filter:url(#filter776)"><path id="path5740" style="color:#000000;text-indent:0;text-transform:none;fill:#ffd42a;fill-opacity:1;fill-rule:evenodd" d="m 713.47,204.89 v 74.226 c -91.83,18.86 -161.52,100.63 -161.52,197.79 0,96.668 69.012,178.12 160.15,197.51 L 645.092,607.408 686.15,566.35 c -26.951,-20.333 -44.214,-52.667 -44.214,-89.444 0,-48.051 29.471,-88.501 71.524,-104.52 v 115.53 L 854.98,346.396 713.47,204.886 Z" /><path id="path2985" style="color:#000000;text-indent:0;text-transform:none;fill:#ffd42a;fill-opacity:1;fill-rule:evenodd" d="m 795.61,279.41 67.008,66.993 -41.074,41.074 c 26.961,20.328 44.23,52.647 44.23,89.428 0,48.051 -29.47,88.512 -71.524,104.54 v -115.55 l -141.52,141.52 141.52,141.52 v -74.226 c 91.812,-18.87 161.51,-100.65 161.51,-197.79 0,-96.667 -69.01,-178.1 -160.15,-197.49 z" /></g><defs id="defs2"><clipPath id="clip0_211_1892"><rect width="115" height="111" fill="white" id="rect2" /></clipPath><filter height="1.0441137" width="1.0594339" y="-0.022056837" x="-0.029716946" style="color-interpolation-filters:sRGB" id="filter776"><feGaussianBlur stdDeviation="5" in="SourceAlpha" result="result1" id="feGaussianBlur764" /><feComposite operator="arithmetic" k2="3.2" k1="-1" k4="-2" result="result3" in2="result1" id="feComposite766" k3="0" /><feColorMatrix values="1 0 0 0 0 0 1 0 0 0 0 0 1 0 0 0 0 0 10 0 " result="result2" id="feColorMatrix768" /><feComposite result="fbSourceGraphic" in="SourceGraphic" operator="out" in2="result2" id="feComposite770" /><feBlend mode="multiply" in="result1" in2="fbSourceGraphic" result="result91" id="feBlend772" /><feBlend mode="screen" in="fbSourceGraphic" in2="result91" id="feBlend774" /></filter></defs></svg>
MULTIFLEXI_EXECUTOR_LOGO
        );
    }

    public static function name(): string
    {
        return _('Kubernetes');
    }

    /**
     * Can this Executor execute given application ?
     *
     * @param Application $app
     */
    public static function usableForApp($app): bool
    {
        return empty($app->getDataValue('ociimage')) === false;
    }

    /**
     * Override setJob to handle file-type config fields for k8s context.
     * File-path environment variables from the executor host are meaningless inside a pod,
     * so we skip extracting files and only keep non-file environment fields.
     */
    public function setJob(\MultiFlexi\Job $job): void
    {
        \MultiFlexi\CommonExecutor::setJob($job);

        $fileStore = new \MultiFlexi\FileStore();
        $jobFiles = $fileStore->extractFilesForJob($this->job);

        foreach ($jobFiles as $file) {
            $this->addStatusMessage(sprintf(
                'Skipping file-path env var %s for Kubernetes execution (host path not available in pod)',
                $file->getCode(),
            ), 'warning');
        }
    }

    /**
     * Return the kubectl command line used (or to be used) for this job.
     */
    public function commandline(): string
    {
        $stored = $this->getDataValue('commandline');

        if (\is_string($stored) && $stored !== '') {
            return $stored;
        }

        $kubernetes = $this->kubernetesConfig();
        $helmConfig = \is_array($kubernetes['helm'] ?? null) ? $kubernetes['helm'] : [];
        $image = $this->job->getApplication()->getDataValue('ociimage');
        $kubeconfig = $this->resolveKubeconfig();
        $namespace = $this->resolveNamespace($helmConfig);

        return sprintf(
            'kubectl --kubeconfig=%s run mf-job-* --restart=Never --image=%s %s -- %s %s',
            escapeshellarg($kubeconfig),
            escapeshellarg((string) $image),
            $namespace ? '--namespace='.escapeshellarg($namespace) : '',
            escapeshellarg($this->executable()),
            $this->cmdparams(),
        );
    }

    /**
     * Launch a command and stream output (used only for the job pod attach).
     */
    public function launch(string $command): ?int
    {
        $this->process = Process::fromShellCommandline($command, null, $this->environment?->getEnvArray() ?? null, null, $this->timeout ?? 32767);

        try {
            $this->process->run(function ($type, $buffer): void {
                if ($this->process) {
                    $this->pid = $this->process->getPid();

                    if ($this->pid) {
                        $this->job->setPid($this->pid);
                    }
                }

                if (Process::ERR === $type) {
                    $this->addOutput($buffer, 'stderr');
                } else {
                    $this->addOutput($buffer, 'stdout');
                }
            });
        } catch (\Exception $exc) {
            $this->addStatusMessage($exc->getMessage(), 'error');
        }

        return $this->process?->getExitCode();
    }

    /**
     * Launch job in Kubernetes using `kubectl run --restart=Never`.
     *
     * Without artifacts: `--attach --rm` streams output directly.
     * With artifacts (app.json ``artifacts`` / ``app_artifacts``): pod is kept
     * Running briefly after the command so `kubectl cp` can read files
     * (exec into Succeeded pods is impossible). Copied files land in
     * ``MULTIFLEXI_TMP`` for ``Job::runEnd()`` → ``artifacts`` table.
     */
    public function launchJob(): void
    {
        $this->jobExitCode = 1;
        $this->jobStdout = '';
        $this->jobStderr = '';
        $this->remappedHostPaths = [];

        if (\MultiFlexi\Application::doesBinaryExist('kubectl') === false) {
            $this->addStatusMessage('kubectl binary is not available in PATH', 'error');

            return;
        }

        $image = (string) ($this->job->getApplication()->getDataValue('ociimage') ?? '');

        if ($image === '') {
            $this->addStatusMessage('Application ociimage is empty; Kubernetes executor requires a container image', 'error');

            return;
        }

        $this->job->setEnvironment($this->environment);

        $kubernetes = $this->kubernetesConfig();
        $helmConfig = \is_array($kubernetes['helm'] ?? null) ? $kubernetes['helm'] : [];
        $artifactConfig = \is_array($kubernetes['artifacts'] ?? null) ? $kubernetes['artifacts'] : [];
        $artifactEnabled = self::artifactEnabled($artifactConfig);

        // Host MULTIFLEXI_TMP paths are invalid inside the pod
        $this->remapHostTempPathsForContainer();

        $cmd = $this->executable();
        $params = $this->cmdparams();

        $podName = 'mf-job-'.($this->job->getMyKey() ?? time()).'-'.substr(md5((string) random_int(1, \PHP_INT_MAX)), 0, 6);
        $this->podName = $podName;

        $kubeconfig = $this->resolveKubeconfig();
        $this->kubeconfig = $kubeconfig;

        $namespace = $this->resolveNamespace($helmConfig);

        if (self::shouldRunHelm($helmConfig)) {
            if ($this->isDeployed($kubernetes, $namespace, $helmConfig) === false) {
                if ($this->runHelmPreDeploy($helmConfig, $kubeconfig) === false) {
                    $this->addStatusMessage('Helm pre-deployment failed, Kubernetes job was not launched', 'error');

                    return;
                }
            }
        }

        $envFlags = [];

        foreach ($this->environment->getEnvArray() as $k => $v) {
            $envFlags[] = '--env='.escapeshellarg($k.'='.$v);
        }

        $envString = $envFlags ? implode(' ', $envFlags) : '';
        $namespaceFlag = $namespace ? '--namespace='.escapeshellarg($namespace) : '';

        $this->addStatusMessage('Kubernetes job launch: '.$podName);

        if ($artifactEnabled) {
            $this->launchJobWithArtifacts(
                $kubeconfig,
                $podName,
                $image,
                $namespaceFlag,
                $envString,
                $cmd,
                $params,
                $artifactConfig,
                $namespace,
            );
            $this->restoreRemappedHostPaths();
        } else {
            $command = sprintf(
                'kubectl --kubeconfig=%s run %s --restart=Never --image=%s %s %s --attach --rm -- %s %s',
                escapeshellarg($kubeconfig),
                escapeshellarg($podName),
                escapeshellarg($image),
                $namespaceFlag,
                $envString,
                escapeshellarg($cmd),
                $params,
            );

            $this->setDataValue('commandline', $command);
            $exit = $this->launch(trim($command));
            $this->jobStdout = $this->process?->getOutput() ?? '';
            $this->jobStderr = $this->process?->getErrorOutput() ?? '';
            $this->jobExitCode = $exit;
            $this->restoreRemappedHostPaths();
        }

        $this->addStatusMessage('Kubernetes job finished: '.($this->jobExitCode === 0 ? 'OK' : 'exit '.$this->jobExitCode));
    }

    public function getErrorOutput(): string
    {
        return $this->jobStderr !== '' ? $this->jobStderr : ($this->process?->getErrorOutput() ?? '');
    }

    public function getExitCode(): int
    {
        return $this->jobExitCode !== null ? $this->jobExitCode : ($this->process?->getExitCode() ?? 0);
    }

    public function getOutput(): string
    {
        return $this->jobStdout !== '' ? $this->jobStdout : ($this->process?->getOutput() ?? '');
    }

    public function meaning(): string
    {
        $code = $this->jobExitCode ?? $this->process?->getExitCode();

        if ($code === null) {
            return _('Unknown');
        }

        return Process::$exitCodes[$code] ?? ($code === 0 ? _('OK') : _('Error'));
    }

    /**
     * Run a helper kubectl/helm command without streaming into job output
     * and without replacing the job Process stored in $this->process.
     */
    private function runQuietCommand(string $command, ?int $timeout = 300): Process
    {
        $process = Process::fromShellCommandline($command, null, $this->environment?->getEnvArray() ?? null, null, $timeout);

        try {
            $process->run();
        } catch (\Exception $exc) {
            $this->addStatusMessage($exc->getMessage(), 'warning');
        }

        return $process;
    }

    /**
     * Check whether application is already deployed in the cluster.
     * If Helm is enabled, use `helm status <release>`; otherwise check k8s deployment.
     *
     * @param array<string, mixed> $kubernetes
     * @param array<string, mixed> $helmConfig
     */
    private function isDeployed(array $kubernetes, ?string $namespace, array $helmConfig): bool
    {
        if (self::shouldRunHelm($helmConfig)) {
            $releaseName = (string) ($helmConfig['releaseName'] ?? '');

            if ($releaseName === '') {
                return false;
            }

            $helmCmd = sprintf(
                'helm --kubeconfig=%s %s status %s',
                escapeshellarg($this->kubeconfig ?? ''),
                $namespace ? '--namespace='.escapeshellarg($namespace) : '',
                escapeshellarg($releaseName),
            );

            return $this->runQuietCommand(trim($helmCmd), 60)->getExitCode() === 0;
        }

        $deployment = (string) ($kubernetes['deployment'] ?? $kubernetes['deploymentName'] ?? $this->job->getApplication()->getDataValue('name'));

        if ($deployment === '') {
            return false;
        }

        $kubectlCmd = sprintf(
            'kubectl --kubeconfig=%s %s get deployment %s',
            escapeshellarg($this->kubeconfig ?? ''),
            $namespace ? '--namespace='.escapeshellarg($namespace) : '',
            escapeshellarg($deployment),
        );

        return $this->runQuietCommand(trim($kubectlCmd), 60)->getExitCode() === 0;
    }

    /**
     * Reconstruct kubernetes config from existing DB fields and sensible defaults.
     * Artifact paths come from ``app_artifacts`` (application.json ``artifacts``)
     * with a fallback to the legacy comma-separated ``apps.artifacts`` column.
     *
     * @return array<string, mixed>
     */
    private function kubernetesConfig(): array
    {
        $app = $this->job->getApplication();
        $helmChart = (string) ($app->getDataValue('helmchart') ?? '');
        $artifactPaths = $this->loadAppArtifactPaths($app);

        $config = [
            'artifacts' => [
                'enabled' => $artifactPaths !== [],
                'paths' => $artifactPaths,
                'keepPodOnFailure' => false,
            ],
        ];

        if ($helmChart === '') {
            return $config;
        }

        $config['helm'] = [
            'enabled' => true,
            'chart' => $helmChart,
            'releaseName' => self::dns1123Name((string) ($app->getDataValue('name') ?? '')),
            'namespace' => 'multiflexi',
            'upgradeInstall' => true,
            'wait' => true,
            'atomic' => false,
            'timeoutSeconds' => 300,
        ];

        return $config;
    }

    /**
     * Artifact path patterns from app_artifacts (preferred) or legacy apps.artifacts.
     *
     * @return list<string>
     */
    private function loadAppArtifactPaths(\MultiFlexi\Application $app): array
    {
        $appId = $app->getMyKey();

        if ($appId) {
            try {
                $rows = $app->getFluentPDO()
                    ->from('app_artifacts')
                    ->where('app_id', $appId)
                    ->fetchAll();

                if (\is_array($rows) && $rows !== []) {
                    $paths = array_values(array_filter(
                        array_map(static fn ($row): string => trim((string) ($row['path'] ?? '')), $rows),
                        static fn (string $p): bool => $p !== '',
                    ));

                    if ($paths !== []) {
                        return $paths;
                    }
                }
            } catch (\Throwable $exc) {
                $this->addStatusMessage('Could not load app_artifacts: '.$exc->getMessage(), 'debug');
            }
        }

        return self::parseArtifactPaths($app->getDataValue('artifacts'));
    }

    /**
     * @param mixed $artifactsRaw
     *
     * @return list<string>
     */
    private static function parseArtifactPaths($artifactsRaw): array
    {
        if (!\is_string($artifactsRaw) || $artifactsRaw === '') {
            return [];
        }

        $paths = array_filter(array_map('trim', explode(',', $artifactsRaw)), static fn (string $p): bool => $p !== '');

        return array_values($paths);
    }

    /**
     * Derive a DNS-1123 subdomain-safe name (max 63 characters).
     */
    private static function dns1123Name(string $name): string
    {
        $releaseName = strtolower(trim(preg_replace('/[^a-z0-9-]+/', '-', strtolower($name)) ?? '', '-'));

        if ($releaseName === '') {
            return 'mf-app';
        }

        if (\strlen($releaseName) > 63) {
            $releaseName = rtrim(substr($releaseName, 0, 63), '-');
        }

        return $releaseName !== '' ? $releaseName : 'mf-app';
    }

    /**
     * @param array<string, mixed> $helmConfig
     */
    private static function shouldRunHelm(array $helmConfig): bool
    {
        return (bool) ($helmConfig['enabled'] ?? false);
    }

    /**
     * @param array<string, mixed> $helmConfig
     */
    private function runHelmPreDeploy(array $helmConfig, string $kubeconfig): bool
    {
        if (\MultiFlexi\Application::doesBinaryExist('helm') === false) {
            $this->addStatusMessage('Helm binary is not available in PATH', 'error');

            return false;
        }

        $releaseName = (string) ($helmConfig['releaseName'] ?? '');
        $chart = (string) ($helmConfig['chart'] ?? '');

        if ($releaseName === '' || $chart === '') {
            $this->addStatusMessage('Helm is enabled but releaseName/chart is missing', 'error');

            return false;
        }

        $helmArgs = [
            'helm',
        ];

        if ((bool) ($helmConfig['upgradeInstall'] ?? true)) {
            $helmArgs[] = 'upgrade';
            $helmArgs[] = '--install';
            $helmArgs[] = $releaseName;
            $helmArgs[] = $chart;
        } else {
            $helmArgs[] = 'install';
            $helmArgs[] = $releaseName;
            $helmArgs[] = $chart;
        }

        $namespace = $this->resolveNamespace($helmConfig);

        if ($namespace !== null && $namespace !== '') {
            $helmArgs[] = '--namespace';
            $helmArgs[] = $namespace;
            $helmArgs[] = '--create-namespace';
        }

        if (isset($helmConfig['timeoutSeconds'])) {
            $helmArgs[] = '--timeout';
            $helmArgs[] = (string) ((int) $helmConfig['timeoutSeconds']).'s';
        }

        if ((bool) ($helmConfig['wait'] ?? true)) {
            $helmArgs[] = '--wait';
        }

        if ((bool) ($helmConfig['atomic'] ?? false)) {
            $helmArgs[] = '--atomic';
        }

        if (\is_array($helmConfig['valuesFiles'] ?? null)) {
            foreach ($helmConfig['valuesFiles'] as $valuesFile) {
                $helmArgs[] = '--values';
                $helmArgs[] = (string) $valuesFile;
            }
        }

        if (\is_array($helmConfig['set'] ?? null)) {
            foreach ($helmConfig['set'] as $key => $value) {
                $helmArgs[] = '--set';
                $helmArgs[] = (string) $key.'='.self::helmSetValue($value);
            }
        }

        $helmCommand = 'KUBECONFIG='.escapeshellarg($kubeconfig).' '.implode(' ', array_map('escapeshellarg', $helmArgs));
        $this->addStatusMessage('Kubernetes Helm pre-deployment: '.$releaseName, 'warning');

        $process = $this->runQuietCommand($helmCommand, (int) ($helmConfig['timeoutSeconds'] ?? 300) + 60);

        if ($process->getExitCode() !== 0) {
            $stderr = trim($process->getErrorOutput());
            $stdout = trim($process->getOutput());
            $this->addStatusMessage('Helm failed: '.($stderr !== '' ? $stderr : $stdout), 'error');

            return false;
        }

        return true;
    }

    /**
     * @param mixed $value
     */
    private static function helmSetValue($value): string
    {
        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (\is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value) ?: '';
    }

    /**
     * Namespace resolution: MULTIFLEXI_K8S_NAMESPACE → Helm namespace → null (cluster default).
     *
     * @param array<string, mixed> $helmConfig
     */
    private function resolveNamespace(array $helmConfig = []): ?string
    {
        $fromEnv = (string) (getenv('MULTIFLEXI_K8S_NAMESPACE') ?: ($this->environment?->getEnvArray()['MULTIFLEXI_K8S_NAMESPACE'] ?? ''));

        if ($fromEnv !== '') {
            return $fromEnv;
        }

        return self::helmNamespace($helmConfig);
    }

    private function resolveKubeconfig(): string
    {
        $home = getenv('HOME') ?: '/root';

        return (string) (getenv('KUBECONFIG') ?: $home.'/.kube/config');
    }

    /**
     * @param array<string, mixed> $helmConfig
     */
    private static function helmNamespace(array $helmConfig): ?string
    {
        $namespace = (string) ($helmConfig['namespace'] ?? '');

        return $namespace !== '' ? $namespace : null;
    }

    /**
     * @param array<string, mixed> $artifactConfig
     */
    private static function artifactEnabled(array $artifactConfig): bool
    {
        if ((bool) ($artifactConfig['enabled'] ?? false) === false) {
            return false;
        }

        $paths = self::artifactPaths($artifactConfig);

        return $paths !== [];
    }

    /**
     * Remap host MULTIFLEXI_TMP (and env values under it) to CONTAINER_TMP in the pod.
     * Artifact definitions (application.json) describe produced files; this only
     * makes host-sanitized write paths usable inside the container.
     */
    private function remapHostTempPathsForContainer(): void
    {
        $hostTmp = rtrim(\MultiFlexi\Defaults::$MULTIFLEXI_TMP, '/');
        $containerTmp = self::CONTAINER_TMP;

        $tmpField = $this->environment->getFieldByCode('MULTIFLEXI_TMP');

        if ($tmpField && (string) $tmpField->getValue() !== '') {
            $this->remappedHostPaths['MULTIFLEXI_TMP'] = (string) $tmpField->getValue();
            $tmpField->setValue($containerTmp);
            $this->addStatusMessage(sprintf('Remapping MULTIFLEXI_TMP → %s for Kubernetes pod', $containerTmp), 'info');
        }

        foreach ($this->environment as $code => $field) {
            if ($code === 'MULTIFLEXI_TMP') {
                continue;
            }

            $value = (string) $field->getValue();

            if ($value === '') {
                continue;
            }

            if (!str_starts_with($value, $hostTmp.'/') && $value !== $hostTmp) {
                continue;
            }

            $this->remappedHostPaths[$code] = $value;
            $containerPath = $containerTmp.'/'.basename($value);
            $field->setValue($containerPath);
            $this->addStatusMessage(sprintf('Remapping %s %s → %s for Kubernetes pod', $code, $value, $containerPath), 'info');
        }
    }

    /**
     * Restore env fields remapped for the pod so Job::runEnd() sees host paths.
     */
    private function restoreRemappedHostPaths(): void
    {
        foreach ($this->remappedHostPaths as $code => $original) {
            $field = $this->environment->getFieldByCode($code);

            if ($field) {
                $field->setValue($original);
            }
        }

        $this->remappedHostPaths = [];
    }

    /**
     * Run without --attach, hold the pod Running after the command so kubectl cp works.
     *
     * @param array<string, mixed> $artifactConfig
     */
    private function launchJobWithArtifacts(
        string $kubeconfig,
        string $podName,
        string $image,
        string $namespaceFlag,
        string $envString,
        string $cmd,
        string $params,
        array $artifactConfig,
        ?string $namespace,
    ): void {
        $inner = trim($cmd.($params !== '' ? ' '.$params : ''));
        $script = $inner.'; ec=$?; printf \'%s\' "$ec" > '.self::CONTAINER_TMP.'/mf-exit; sleep 180; exit $ec';

        $command = sprintf(
            'kubectl --kubeconfig=%s run %s --restart=Never --image=%s %s %s --command -- /bin/sh -c %s',
            escapeshellarg($kubeconfig),
            escapeshellarg($podName),
            escapeshellarg($image),
            $namespaceFlag,
            $envString,
            escapeshellarg($script),
        );

        $this->setDataValue('commandline', $command);

        $create = $this->runQuietCommand(trim($command), 120);

        if ($create->getExitCode() !== 0) {
            $this->addStatusMessage('Failed to create Kubernetes pod: '.$create->getErrorOutput(), 'error');
            $this->jobExitCode = $create->getExitCode() ?? 1;
            $this->jobStderr = $create->getErrorOutput();

            return;
        }

        $waitCmd = sprintf(
            'kubectl --kubeconfig=%s %s wait --for=condition=Ready --timeout=180s pod/%s',
            escapeshellarg($kubeconfig),
            $namespaceFlag,
            escapeshellarg($podName),
        );
        $this->runQuietCommand(trim($waitCmd), 200);

        $this->jobExitCode = $this->waitForExitMarker($namespace, $podName, 600);
        $this->capturePodLogs($namespace, $podName);
        $this->collectArtifacts($artifactConfig, $namespace, $podName);
        $this->cleanupPod($namespace, $podName, (bool) ($artifactConfig['keepPodOnFailure'] ?? false), (int) ($this->jobExitCode ?? 1));
    }

    /**
     * Poll until /tmp/mf-exit appears inside the pod, then return its contents as exit code.
     */
    private function waitForExitMarker(?string $namespace, string $podName, int $timeoutSeconds): int
    {
        $deadline = time() + $timeoutSeconds;
        $namespaceFlag = $namespace ? '--namespace='.escapeshellarg($namespace) : '';
        $marker = self::CONTAINER_TMP.'/mf-exit';

        while (time() < $deadline) {
            $testCmd = sprintf(
                'kubectl --kubeconfig=%s %s exec %s -- test -f %s',
                escapeshellarg($this->kubeconfig ?? ''),
                $namespaceFlag,
                escapeshellarg($podName),
                escapeshellarg($marker),
            );
            $test = $this->runQuietCommand(trim($testCmd), 30);

            if ($test->getExitCode() === 0) {
                $catCmd = sprintf(
                    'kubectl --kubeconfig=%s %s exec %s -- cat %s',
                    escapeshellarg($this->kubeconfig ?? ''),
                    $namespaceFlag,
                    escapeshellarg($podName),
                    escapeshellarg($marker),
                );
                $cat = $this->runQuietCommand(trim($catCmd), 30);
                $raw = trim($cat->getOutput());

                return is_numeric($raw) ? (int) $raw : 1;
            }

            sleep(2);
        }

        $this->addStatusMessage('Timed out waiting for job exit marker in pod '.$podName, 'error');

        return 1;
    }

    private function capturePodLogs(?string $namespace, string $podName): void
    {
        $namespaceFlag = $namespace ? '--namespace='.escapeshellarg($namespace) : '';
        $logsCmd = sprintf(
            'kubectl --kubeconfig=%s %s logs %s --tail=10000',
            escapeshellarg($this->kubeconfig ?? ''),
            $namespaceFlag,
            escapeshellarg($podName),
        );

        $logsProcess = $this->runQuietCommand(trim($logsCmd), 60);

        if ($logsProcess->isSuccessful()) {
            $out = $logsProcess->getOutput();
            $this->jobStdout = $out;
            if ($out !== '') {
                $this->addOutput($out, 'stdout');
            }
        } else {
            $err = $logsProcess->getErrorOutput();
            $this->addStatusMessage('Failed to fetch pod logs: '.$err, 'warning');
            $this->jobStderr = $err;
        }
    }

    /**
     * @param array<string, mixed> $artifactConfig
     *
     * @return list<string>
     */
    private static function artifactPaths(array $artifactConfig): array
    {
        if (\is_array($artifactConfig['paths'] ?? null)) {
            $paths = array_filter(array_map(static fn ($p): string => trim((string) $p), $artifactConfig['paths']), static fn (string $p): bool => $p !== '');

            return array_values($paths);
        }

        // Backward compatible single outputPath (application.json kubernetes.artifacts)
        $single = (string) ($artifactConfig['outputPath'] ?? '');

        return $single !== '' ? [$single] : [];
    }

    /**
     * Copy files matching application artifact path patterns from the pod into
     * host MULTIFLEXI_TMP so Job::runEnd() / Application::getResultFiles() can
     * store them in the artifacts table (not FileStore).
     *
     * @param array<string, mixed> $artifactConfig
     */
    private function collectArtifacts(array $artifactConfig, ?string $namespace, string $podName): void
    {
        $patterns = self::artifactPaths($artifactConfig);

        if ($patterns === []) {
            $this->addStatusMessage('Artifact extraction enabled, but no artifact paths are configured', 'warning');

            return;
        }

        $hostTmp = \MultiFlexi\Defaults::$MULTIFLEXI_TMP;

        if (is_dir($hostTmp) === false) {
            mkdir($hostTmp, 0o775, true);
        }

        $podFiles = $this->listPodDirectory($namespace, $podName, self::CONTAINER_TMP);
        $copied = [];

        foreach ($podFiles as $file) {
            if ($file === '' || $file === 'mf-exit' || isset($copied[$file])) {
                continue;
            }

            if (!self::matchesArtifactPattern($file, $patterns)) {
                continue;
            }

            $podPath = self::CONTAINER_TMP.'/'.$file;
            $targetFile = $hostTmp.'/'.$file;

            $copyCmd = sprintf(
                'kubectl --kubeconfig=%s %s cp %s %s',
                escapeshellarg($this->kubeconfig ?? ''),
                $namespace ? '--namespace='.escapeshellarg($namespace) : '',
                escapeshellarg($podName.':'.$podPath),
                escapeshellarg($targetFile),
            );

            $copyProcess = $this->runQuietCommand(trim($copyCmd), 120);

            if ($copyProcess->getExitCode() !== 0) {
                $this->addStatusMessage('Artifact copy failed for '.$podPath.': '.$copyProcess->getErrorOutput(), 'warning');

                continue;
            }

            $copied[$file] = true;
            $this->addStatusMessage('Artifact copied to '.$targetFile.' (will be stored by Job::runEnd)', 'success');
        }

        if ($copied === []) {
            $this->addStatusMessage('No artifact files matched patterns ['.implode(', ', $patterns).'] in '.self::CONTAINER_TMP, 'warning');
        }
    }

    /**
     * @param list<string> $patterns
     */
    private static function matchesArtifactPattern(string $filename, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            $pattern = trim($pattern);

            if ($pattern === '') {
                continue;
            }

            if ($filename === $pattern || $filename === basename($pattern)) {
                return true;
            }

            if (@preg_match('/'.$pattern.'/', $filename) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function listPodDirectory(?string $namespace, string $podName, string $dir): array
    {
        $namespaceFlag = $namespace ? '--namespace='.escapeshellarg($namespace) : '';
        $lsCmd = sprintf(
            'kubectl --kubeconfig=%s %s exec %s -- ls -1 %s',
            escapeshellarg($this->kubeconfig ?? ''),
            $namespaceFlag,
            escapeshellarg($podName),
            escapeshellarg($dir),
        );

        $ls = $this->runQuietCommand(trim($lsCmd), 60);

        if ($ls->getExitCode() !== 0) {
            $this->addStatusMessage('Could not list '.$dir.' in pod: '.$ls->getErrorOutput(), 'warning');

            return [];
        }

        $files = preg_split('/\r\n|\r|\n/', trim($ls->getOutput())) ?: [];

        return array_values(array_filter($files, static fn (string $f): bool => $f !== ''));
    }

    private function cleanupPod(?string $namespace, string $podName, bool $keepPodOnFailure, int $exitCode): void
    {
        if ($keepPodOnFailure && $exitCode !== 0) {
            $this->addStatusMessage('Pod '.$podName.' kept for troubleshooting', 'warning');

            return;
        }

        $deleteCmd = sprintf(
            'kubectl --kubeconfig=%s %s delete pod %s --ignore-not-found=true --wait=false',
            escapeshellarg($this->kubeconfig ?? ''),
            $namespace ? '--namespace='.escapeshellarg($namespace) : '',
            escapeshellarg($podName),
        );

        $this->runQuietCommand(trim($deleteCmd), 60);
    }
}
