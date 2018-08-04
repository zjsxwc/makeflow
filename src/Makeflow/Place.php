<?php
/**
 * Created by PhpStorm.
 * User: wangchao
 * Date: 31/07/2018
 * Time: 11:31 AM
 */

namespace App\Makeflow;

use App\Makeflow\Dashboard\Entity\PlaceUser;
use App\Makeflow\Dashboard\Entity\Workspace;
use App\Repository\UserRepository;
use Symfony\Component\HttpFoundation\Response;

use Doctrine\ORM\EntityManagerInterface;
use SensioLabs\Security\Exception\RuntimeException;
use Symfony\Component\HttpFoundation\Request;

abstract class Place
{

    /** @var Makeflow */
    protected $makeflow;

    /** @var EntityManagerInterface */
    protected $entityManager;
    /** @var \Twig_Environment */
    protected $twig;

    /**
     * Place constructor.
     * @param Makeflow $makeflow
     * @param EntityManagerInterface $entityManager
     * @param \Twig_Environment $twig
     */
    public function __construct(Makeflow $makeflow, EntityManagerInterface $entityManager, \Twig_Environment $twig)
    {
        $this->makeflow = $makeflow;
        $this->entityManager = $entityManager;
        $this->twig = $twig;

        $this->initialize();
    }

    protected function initialize()
    {
        $this->getName();
    }


    protected $name;

    public $label = "";

    public $description = "";


    public function getLabel()
    {
        if ($this->label) {
            return $this->label;
        }
        return $this->getName();
    }

    public function getName()
    {
        if (!$this->name) {
            $className = get_class($this);
            $nameSegments = explode("\\", $className);
            $baseName = $nameSegments[count($nameSegments) - 1];
            list($name) = explode("Place", $baseName);
            $this->name = $name;
        }
        return $this->name;
    }

    public function setName($name)
    {
        $this->name = $name;
    }


    /**
     * @param Request $request
     * @param Workspace $workspace
     * @return Response
     */
    public function processAction(Request $request, Workspace $workspace)
    {
        $request->getMethod();
        $workspace->getId();
        throw new RuntimeException("Need processAction()");
    }

    /**
     * @param string $view
     * @param array $parameters
     * @param Response|null $response
     * @return Response
     * @inheritdoc
     */
    public function render(string $view, array $parameters = array(), Response $response = null)
    {
        if (!$response) {
            $response = new Response();
        }
        $twigNamespace = $this->makeflow->getName() . 'Makeflow';
        $content = $this->twig->render("@" . $twigNamespace . "/" . $view, $parameters);
        $response->setContent($content);
        return $response;
    }


    public function isUserAllowedInPlace(int $userId)
    {
        $repo = $this->entityManager->getRepository("MakeflowDashboard:PlaceUser");
        $mayPlaceUser = $repo->findOneBy([
            "userId" => $userId,
            "makeflowName" => $this->makeflow->getName(),
            "placeName" => $this->getName(),
        ]);
        if ($mayPlaceUser) {
            return true;
        }
        return false;
    }

    /**
     * @return \App\Entity\User[]
     */
    public function getUsers()
    {
        $repo = $this->entityManager->getRepository("MakeflowDashboard:PlaceUser");
        /** @var PlaceUser[] $placeUserList */
        $placeUserList = $repo->findBy([
            "makeflowName" => $this->makeflow->getName(),
            "placeName" => $this->getName()
        ]);
        $userIdList = [];
        foreach ($placeUserList as $placeUser) {
            $userIdList[] = $placeUser->getUserId();
        }
        /** @var UserRepository $userRepo */
        $userRepo = $this->entityManager->getRepository("App:User");
        return $userRepo->findUsersByIdList($userIdList);

    }

    /**
     * @param $userIdList
     * @return bool
     * @throws \Doctrine\DBAL\DBALException
     */
    public function removeUsers(array $userIdList)
    {
        $repo = $this->entityManager->getRepository("MakeflowDashboard:PlaceUser");

        return $repo->deleteByMakeflowNameAndPlaceNameAndUserIdList($this->makeflow->getName(), $this->getName(), $userIdList);

    }

    /**
     * @param array $userIdList
     */
    public function bindUsersToPlace(array $userIdList)
    {
        $repo = $this->entityManager->getRepository("MakeflowDashboard:PlaceUser");
        $existPlaceUserList = $repo->findByMakeflowNameAndPlaceNameList($this->makeflow->getName(), [$this->getName()]);
        $alreadyExistUserIdList = [];
        foreach ($existPlaceUserList as $existPlaceUser) {
            $alreadyExistUserIdList[] = $existPlaceUser->getUserId();
        }

        foreach ($userIdList as $userId) {
            if (in_array($userId, $alreadyExistUserIdList)) {
                continue;
            }
            $placeUser = new PlaceUser();
            $placeUser->setUserId(intval($userId));
            $placeUser->setMakeflowName($this->makeflow->getName());
            $placeUser->setPlaceName($this->getName());
            $this->entityManager->persist($placeUser);
        }
        $this->entityManager->flush();
    }


    protected function finishPlace(Workspace $workspace)
    {
        if ($workspace->getMakeflowName() !== get_class($this->makeflow)) {
            throw new  \LogicException(sprintf('Place %s makeflow class %s not for workspace makeflow class %s', $this->getName(), get_class($this->makeflow), $workspace->getMakeflowName()));
        }
        $placeName = $this->getName();
        $workspace->addDirectory($placeName);

        //fixme 通过count判断是否完成workflow可能会不准确
        if (count($this->makeflow->getMakeflowConfig()) === count($workspace->getDirectory())) {
            $workspace->setStatus(Workspace::STATUS_FINISHED);
        }
        $this->entityManager->flush();
    }

}