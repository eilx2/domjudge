<?php declare(strict_types=1);

namespace DOMJudgeBundle\Controller\Jury;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Expr\Join;
use DOMJudgeBundle\Controller\BaseController;
use DOMJudgeBundle\Entity\Contest;
use DOMJudgeBundle\Entity\ContestProblem;
use DOMJudgeBundle\Entity\RemovedInterval;
use DOMJudgeBundle\Form\Type\ContestType;
use DOMJudgeBundle\Form\Type\FinalizeContestType;
use DOMJudgeBundle\Form\Type\RemovedIntervalType;
use DOMJudgeBundle\Service\DOMJudgeService;
use DOMJudgeBundle\Service\EventLogService;
use DOMJudgeBundle\Utils\Utils;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/jury")
 * @Security("has_role('ROLE_JURY')")
 */
class ContestController extends BaseController
{
    /**
     * @var EntityManagerInterface
     */
    protected $entityManager;

    /**
     * @var DOMJudgeService
     */
    protected $DOMJudgeService;

    /**
     * @var EventLogService
     */
    protected $eventLogService;

    /**
     * TeamCategoryController constructor.
     * @param EntityManagerInterface $entityManager
     * @param DOMJudgeService        $DOMJudgeService
     * @param EventLogService        $eventLogService
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        DOMJudgeService $DOMJudgeService,
        EventLogService $eventLogService
    ) {
        $this->entityManager   = $entityManager;
        $this->DOMJudgeService = $DOMJudgeService;
        $this->eventLogService = $eventLogService;
    }

    /**
     * @Route("/contests/", name="jury_contests")
     * @param Request         $request
     * @param KernelInterface $kernel
     * @return \Symfony\Component\HttpFoundation\Response
     * @throws \Doctrine\ORM\NonUniqueResultException
     */
    public function indexAction(Request $request, KernelInterface $kernel)
    {
        $em = $this->entityManager;

        if ($doNow = (array)$request->request->get('donow')) {
            $times         = ['activate', 'start', 'freeze', 'end', 'unfreeze', 'finalize', 'deactivate'];
            $start_actions = ['delay_start', 'resume_start'];
            $actions       = array_merge($times, $start_actions);

            if (!$this->isGranted('ROLE_ADMIN')) {
                throw new AccessDeniedHttpException();
            }
            /** @var Contest $contest */
            $contest = $em->getRepository(Contest::class)->find($request->request->get('contest'));
            if (!$contest) {
                throw new NotFoundHttpException('contest not found');
            }

            $time = key($doNow);
            if (!in_array($time, $actions, true)) {
                throw new BadRequestHttpException(sprintf("Unknown value '%s' for timetype", $time));
            }

            if ($time === 'finalize') {
                return $this->redirectToRoute('jury_contest_finalize', ['contestId' => $contest->getCid()]);
            }

            $now       = (int)floor(Utils::now());
            $nowstring = strftime('%Y-%m-%d %H:%M:%S ', $now) . date_default_timezone_get();
            $this->DOMJudgeService->auditlog('contest', $contest->getCid(), $time . ' now', $nowstring);

            // Special case delay/resume start (only sets/unsets starttime_undefined).
            $maxSeconds = Contest::STARTTIME_UPDATE_MIN_SECONDS_BEFORE;
            if (in_array($time, $start_actions, true)) {
                $enabled = $time === 'delay_start' ? 0 : 1;
                if (Utils::difftime((float)$contest->getStarttime(false), $now) <= $maxSeconds) {
                    throw new BadRequestHttpException(sprintf("Cannot %s less than %d seconds before contest start.",
                                                              $time, $maxSeconds));
                }
                $contest->setStarttimeEnabled($enabled);
                $em->flush();
                $this->eventLogService->log('contest', $contest->getCid(), EventLogService::ACTION_UPDATE,
                                            $contest->getCid());
                return $this->redirectToRoute('jury_contests', ['edited' => 1]);
            }

            $juryTimeData = $contest->getJuryTimeData();
            if (!$juryTimeData[$time]['show_button']) {
                throw new BadRequestHttpException(sprintf('Cannot update %s time at this moment', $time));
            }

            // starttime is special because other, relative times depend on it.
            if ($time == 'start') {
                if ($contest->getStarttimeEnabled() && Utils::difftime((float)$contest->getStarttime(false),
                                                                       $now) <= $maxSeconds) {
                    throw new BadRequestHttpException(sprintf("Cannot update starttime less than %d seconds before contest start.",
                                                              $maxSeconds));
                }
                $contest
                    ->setStarttime($now)
                    ->setStarttimeString($nowstring)
                    ->setStarttimeEnabled(true);
                $em->flush();

                $this->eventLogService->log('contest', $contest->getCid(), EventLogService::ACTION_UPDATE,
                                            $contest->getCid());
                return $this->redirectToRoute('jury_contests', ['edited' => 1]);
            } else {
                $method = sprintf('set%stimeString', $time);
                $contest->{$method}($nowstring);
                $em->flush();
                $this->eventLogService->log('contest', $contest->getCid(), EventLogService::ACTION_UPDATE,
                                            $contest->getCid());
                return $this->redirectToRoute('jury_contests');
            }
        }

        $contests = $em->createQueryBuilder()
            ->select('c', 'COUNT(t.teamid) AS num_teams')
            ->from('DOMJudgeBundle:Contest', 'c')
            ->leftJoin('c.teams', 't')
            ->orderBy('c.starttime', 'DESC')
            ->groupBy('c.cid')
            ->getQuery()->getResult();

        $table_fields = [
            'cid' => ['title' => 'CID', 'sort' => true],
            'shortname' => ['title' => 'shortname', 'sort' => true],
            'name' => ['title' => 'name', 'sort' => true],
            'activatetime' => ['title' => 'activate', 'sort' => true],
            'starttime' => ['title' => 'start', 'sort' => true, 'default_sort' => true, 'default_sort_order' => 'desc'],
            'endtime' => ['title' => 'end', 'sort' => true],
        ];

        $currentContests = $this->DOMJudgeService->getCurrentContests();

        $timeFormat = (string)$this->DOMJudgeService->dbconfig_get('time_format', '%H:%M');

        $etcDir = $this->DOMJudgeService->getDomjudgeEtcDir();
        require_once $etcDir . '/domserver-config.php';

        if (ALLOW_REMOVED_INTERVALS) {
            $table_fields['num_removed_intervals'] = ['title' => '# removed<br/>intervals', 'sort' => true];
            $removedIntervals                      = $em->createQueryBuilder()
                ->from('DOMJudgeBundle:RemovedInterval', 'i', 'i.cid')
                ->select('COUNT(i.intervalid) AS num_removed_intervals', 'i.cid')
                ->groupBy('i.cid')
                ->getQuery()
                ->getResult();
        } else {
            $removedIntervals = [];
        }

        $problems = $em->createQueryBuilder()
            ->from('DOMJudgeBundle:ContestProblem', 'cp', 'cp.cid')
            ->select('COUNT(cp.probid) AS num_problems', 'cp.cid')
            ->groupBy('cp.cid')
            ->getQuery()
            ->getResult();

        $table_fields = array_merge($table_fields, [
            'process_balloons' => ['title' => 'process<br/>balloons?', 'sort' => true],
            'num_teams' => ['title' => '# teams', 'sort' => true],
            'num_problems' => ['title' => '# problems', 'sort' => true],
        ]);

        // Insert external ID field when configured to use it
        if ($externalIdField = $this->eventLogService->externalIdFieldForEntity(Contest::class)) {
            $table_fields = array_slice($table_fields, 0, 1, true) +
                [$externalIdField => ['title' => 'external<br/>ID', 'sort' => true]] +
                array_slice($table_fields, 1, null, true);
        }

        $propertyAccessor = PropertyAccess::createPropertyAccessor();
        $contests_table   = [];
        foreach ($contests as $contestData) {
            /** @var Contest $contest */
            $contest        = $contestData[0];
            $contestdata    = [];
            $contestactions = [];
            // Get whatever fields we can from the contest object itself
            foreach ($table_fields as $k => $v) {
                if ($propertyAccessor->isReadable($contest, $k)) {
                    $contestdata[$k] = ['value' => $propertyAccessor->getValue($contest, $k)];
                }
            }

            if ($this->isGranted('ROLE_ADMIN')) {
                $contestactions[] = [
                    'icon' => 'edit',
                    'title' => 'edit this contest',
                    'link' => $this->generateUrl('jury_contest_edit', [
                        'contestId' => $contest->getCid(),
                    ])
                ];
                $contestactions[] = [
                    'icon' => 'trash-alt',
                    'title' => 'delete this contest',
                    'link' => $this->generateUrl('jury_contest_delete', [
                        'contestId' => $contest->getCid(),
                    ]),
                    'ajaxModal' => true,
                ];
            }

            $contestdata['process_balloons'] = ['value' => $contest->getProcessBalloons() ? 'yes' : 'no'];
            if ($contest->getPublic()) {
                $contestdata['num_teams'] = ['value' => '<i>all</i>'];
            } else {
                $contestdata['num_teams'] = ['value' => $contestData['num_teams']];
            }

            if (ALLOW_REMOVED_INTERVALS) {
                $contestdata['num_removed_intervals'] = ['value' => $removedIntervals[$contest->getCid()]['num_removed_intervals'] ?? 0];
            }
            $contestdata['num_problems'] = ['value' => $problems[$contest->getCid()]['num_problems'] ?? 0];

            $timeFields = [
                'activate',
                'start',
                'end',
            ];
            foreach ($timeFields as $timeField) {
                $time = $contestdata[$timeField . 'time']['value'];
                if (!$contest->getStarttimeEnabled() && $timeField != 'activate') {
                    $time      = null;
                    $timeTitle = null;
                }
                if ($time === null) {
                    $timeValue = '-';
                    $timeTitle = '-';
                } else {
                    $timeValue = Utils::printtime($time, $timeFormat);
                    $timeTitle = Utils::printtime($time, '%Y-%m-%d %H:%M:%S (%Z)');
                }
                $contestdata[$timeField . 'time']['value']     = $timeValue;
                $contestdata[$timeField . 'time']['sortvalue'] = $time;
                $contestdata[$timeField . 'time']['title']     = $timeTitle;
            }

            $styles = [];
            if (!$contest->getEnabled()) {
                $styles[] = 'disabled';
            }
            if (in_array($contest->getCid(), array_keys($currentContests))) {
                $styles[] = 'highlight';
            }
            $contests_table[] = [
                'data' => $contestdata,
                'actions' => $contestactions,
                'link' => $this->generateUrl('jury_contest', ['contestId' => $contest->getCid()]),
                'cssclass' => implode(' ', $styles),
            ];
        }

        /** @var Contest $upcomingContest */
        $upcomingContest = $em->createQueryBuilder()
            ->from('DOMJudgeBundle:Contest', 'c')
            ->select('c')
            ->andWhere('c.activatetime > :now')
            ->andWhere('c.enabled = 1')
            ->setParameter(':now', Utils::now())
            ->orderBy('c.activatetime')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $this->render('@DOMJudge/jury/contests.html.twig', [
            'upcoming_contest' => $upcomingContest,
            'contests_table' => $contests_table,
            'table_fields' => $table_fields,
            'num_actions' => $this->isGranted('ROLE_ADMIN') ? 2 : 0,
            'edited' => $request->query->getBoolean('edited'),
        ]);
    }

    /**
     * @Route("/contests/{contestId}", name="jury_contest", requirements={"contestId": "\d+"})
     * @param Request $request
     * @param int     $contestId
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function viewAction(Request $request, int $contestId)
    {
        /** @var Contest $contest */
        $contest = $this->entityManager->getRepository(Contest::class)->find($contestId);
        if (!$contest) {
            throw new NotFoundHttpException(sprintf('Contest with ID %s not found', $contestId));
        }

        $etcDir = $this->DOMJudgeService->getDomjudgeEtcDir();
        require_once $etcDir . '/domserver-config.php';

        $newRemovedInterval = new RemovedInterval();
        $newRemovedInterval->setContest($contest);
        $contest->addRemovedInterval($newRemovedInterval);
        $form = $this->createForm(RemovedIntervalType::class, $newRemovedInterval);
        $form->handleRequest($request);
        if ($this->isGranted('ROLE_ADMIN') && $form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($newRemovedInterval);
            $this->entityManager->flush();

            return $this->redirectToRoute('jury_contest', ['contestId' => $contestId]);
        }

        /** @var RemovedInterval[] $removedIntervals */
        $removedIntervals = $this->entityManager->createQueryBuilder()
            ->from('DOMJudgeBundle:RemovedInterval', 'i')
            ->select('i')
            ->andWhere('i.contest = :contest')
            ->setParameter(':contest', $contest)
            ->orderBy('i.starttime')
            ->getQuery()
            ->getResult();

        /** @var ContestProblem[] $problems */
        $problems = $this->entityManager->createQueryBuilder()
            ->from('DOMJudgeBundle:ContestProblem', 'cp')
            ->join('cp.problem', 'p')
            ->select('cp', 'p')
            ->andWhere('cp.contest = :contest')
            ->setParameter(':contest', $contest)
            ->orderBy('cp.shortname')
            ->getQuery()
            ->getResult();

        return $this->render('@DOMJudge/jury/contest.html.twig', [
            'contest' => $contest,
            'isActive' => isset($this->DOMJudgeService->getCurrentContests()[$contest->getCid()]),
            'allowRemovedIntervals' => ALLOW_REMOVED_INTERVALS,
            'removedIntervalForm' => $form->createView(),
            'removedIntervals' => $removedIntervals,
            'problems' => $problems,
        ]);
    }

    /**
     * @Route("/contests/{contestId}/remove-interval/{intervalId}", name="jury_contest_remove_interval",
     *                                                              requirements={"contestId": "\d+"},
     *                                                              methods={"POST"})
     * @param int $contestId
     * @param int $intervalId
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function removeIntervalAction(int $contestId, int $intervalId)
    {
        /** @var Contest $contest */
        $contest = $this->entityManager->getRepository(Contest::class)->find($contestId);
        if (!$contest) {
            throw new NotFoundHttpException(sprintf('Contest with ID %s not found', $contestId));
        }

        /** @var RemovedInterval $removedInterval */
        $removedInterval = $this->entityManager->getRepository(RemovedInterval::class)->find($intervalId);
        if (!$contest) {
            throw new NotFoundHttpException(sprintf('Removed interval with ID %s not found', $intervalId));
        }

        if ($removedInterval->getContest()->getCid() !== $contest->getCid()) {
            throw new NotFoundHttpException('Removed interval is of wrong contest');
        }

        $contest->removeRemovedInterval($removedInterval);
        $this->entityManager->remove($removedInterval);
        // Recalculate timing
        $contest->setStarttimeString($contest->getStarttimeString());
        $this->entityManager->flush();

        return $this->redirectToRoute('jury_contest', ['contestId' => $contest->getCid()]);
    }

    /**
     * @Route("/contests/{contestId}/edit", name="jury_contest_edit", requirements={"contestId": "\d+"})
     * @Security("has_role('ROLE_ADMIN')")
     * @param Request $request
     * @param int     $contestId
     * @return \Symfony\Component\HttpFoundation\RedirectResponse|\Symfony\Component\HttpFoundation\Response
     * @throws \Exception
     */
    public function editAction(Request $request, int $contestId)
    {
        /** @var Contest $contest */
        $contest = $this->entityManager->getRepository(Contest::class)->find($contestId);
        if (!$contest) {
            throw new NotFoundHttpException(sprintf('Contest with ID %s not found', $contestId));
        }

        $form = $this->createForm(ContestType::class, $contest);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->saveEntity($this->entityManager, $this->eventLogService, $this->DOMJudgeService, $contest,
                              $contest->getCid(), false);
            return $this->redirect($this->generateUrl('jury_contest',
                                                      ['contestId' => $contest->getcid()]));
        }

        return $this->render('@DOMJudge/jury/contest_edit.html.twig', [
            'contest' => $contest,
            'form' => $form->createView(),
        ]);
    }

    /**
     * @Route("/contests/{contestId}/delete", name="jury_contest_delete", requirements={"contestId": "\d+"})
     * @Security("has_role('ROLE_ADMIN')")
     * @param Request $request
     * @param int     $contestId
     * @return \Symfony\Component\HttpFoundation\RedirectResponse|\Symfony\Component\HttpFoundation\Response
     * @throws \Exception
     */
    public function deleteAction(Request $request, int $contestId)
    {
        /** @var Contest $contest */
        $contest = $this->entityManager->getRepository(Contest::class)->find($contestId);
        if (!$contest) {
            throw new NotFoundHttpException(sprintf('Contest with ID %s not found', $contestId));
        }

        return $this->deleteEntity($request, $this->entityManager, $this->DOMJudgeService, $contest,
                                   $contest->getName(), $this->generateUrl('jury_contests'));
    }

    /**
     * @Route("/contests/{contestId}/problems/{probId}/delete", name="jury_contest_problem_delete", requirements={"contestId":
     *                                                          "\d+", "probId": "\d+"})
     * @Security("has_role('ROLE_ADMIN')")
     * @param Request $request
     * @param int     $contestId
     * @return \Symfony\Component\HttpFoundation\RedirectResponse|\Symfony\Component\HttpFoundation\Response
     * @throws \Exception
     */
    public function deleteProblemAction(Request $request, int $contestId, int $probId)
    {
        /** @var ContestProblem $contestProblem */
        $contestProblem = $this->entityManager->getRepository(ContestProblem::class)->find([
                                                                                               'cid' => $contestId,
                                                                                               'probid' => $probId
                                                                                           ]);
        if (!$contestProblem) {
            throw new NotFoundHttpException(sprintf('Contest problem with contest ID %s and problem ID %s not found', $contestId, $probId));
        }

        return $this->deleteEntity($request, $this->entityManager, $this->DOMJudgeService, $contestProblem,
                                   $contestProblem->getShortname(), $this->generateUrl('jury_contest', ['contestId' => $contestId]));
    }

    /**
     * @Route("/contests/add", name="jury_contest_add")
     * @Security("has_role('ROLE_ADMIN')")
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function addAction(Request $request)
    {
        $contest = new Contest();
        // Set default activate time
        $contest->setActivatetimeString(strftime('%Y-%m-%d %H:%M:00 ') . date_default_timezone_get());

        $form = $this->createForm(ContestType::class, $contest);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->transactional(function () use ($contest) {
                // A little 'hack': we need to first persist and save the contest, before we can persist and
                // save the problem, because we need a contest ID
                /** @var ContestProblem[] $problems */
                $problems = $contest->getProblems()->toArray();
                foreach ($contest->getProblems() as $problem) {
                    $contest->removeProblem($problem);
                }
                $this->entityManager->persist($contest);
                $this->entityManager->flush();

                // Now we can assign the problems to the contest and persist them
                foreach ($problems as $problem) {
                    $problem
                        ->setContest($contest)
                        ->setCid($contest->getCid());
                    $this->entityManager->persist($problem);
                }
                $this->saveEntity($this->entityManager, $this->eventLogService, $this->DOMJudgeService, $contest,
                                  $contest->getCid(), true);
            });
            return $this->redirect($this->generateUrl('jury_contest',
                                                      ['contestId' => $contest->getcid()]));
        }

        return $this->render('@DOMJudge/jury/contest_add.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    /**
     * @Route("/contests/{contestId}/finalize", name="jury_contest_finalize")
     * @Security("has_role('ROLE_ADMIN')")
     * @param Request $request
     * @param int     $contestId
     * @return \Symfony\Component\HttpFoundation\RedirectResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function finalizeAction(Request $request, int $contestId)
    {
        /** @var Contest $contest */
        $contest  = $this->entityManager->getRepository(Contest::class)->find($contestId);
        $blockers = [];
        if (Utils::difftime((float)$contest->getEndtime(), Utils::now()) > 0) {
            $blockers[] = sprintf('Contest not ended yet (will end at %s)',
                                  Utils::printtime($contest->getEndtime(), '%Y-%m-%d %H:%M:%S (%Z)'));
        }

        /** @var int[] $submissionIds */
        $submissionIds = array_map(function (array $data) {
            return $data['submitid'];
        }, $this->entityManager->createQueryBuilder()
               ->from('DOMJudgeBundle:Submission', 's')
               ->join('s.judgings', 'j', Join::WITH, 'j.valid = 1')
               ->select('s.submitid')
               ->andWhere('s.contest = :contest')
               ->andWhere('s.valid = true')
               ->andWhere('j.result IS NULL')
               ->setParameter(':contest', $contest)
               ->orderBy('s.submitid')
               ->getQuery()
               ->getResult()
        );

        if (count($submissionIds) > 0) {
            $blockers[] = 'Unjudged submissions found: s' . implode(', s', $submissionIds);
        }

        /** @var int[] $clarificationIds */
        $clarificationIds = array_map(function (array $data) {
            return $data['clarid'];
        }, $this->entityManager->createQueryBuilder()
               ->from('DOMJudgeBundle:Clarification', 'c')
               ->select('c.clarid')
               ->andWhere('c.contest = :contest')
               ->andWhere('c.answered = false')
               ->setParameter(':contest', $contest)
               ->getQuery()
               ->getResult()
        );
        if (count($clarificationIds) > 0) {
            $blockers[] = 'Unanswered clarifications found: ' . implode(', ', $clarificationIds);
        }

        if (empty($contest->getFinalizecomment())) {
            $contest->setFinalizecomment(sprintf('Finalized by: %s', $this->DOMJudgeService->getUser()->getName()));
        }
        $form = $this->createForm(FinalizeContestType::class, $contest);

        if (empty($blockers)) {
            $form->handleRequest($request);

            if ($form->isSubmitted() && $form->isValid()) {
                $contest->setFinalizetime(Utils::now());
                $this->entityManager->flush();
                $this->DOMJudgeService->auditlog('contest', $contest->getCid(), 'finalized',
                                                 $contest->getFinalizecomment());
                return $this->redirectToRoute('jury_contest', ['contestId' => $contest->getCid()]);
            }
        }

        return $this->render('@DOMJudge/jury/contest_finalize.html.twig', [
            'contest' => $contest,
            'blockers' => $blockers,
            'form' => $form->createView(),
        ]);
    }
}
