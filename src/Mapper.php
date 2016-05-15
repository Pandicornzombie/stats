<?php

namespace PHPWorldWide\Stats;

use PHPWorldWide\Stats\Collection\UserCollection;
use PHPWorldWide\Stats\Collection\TopicCollection;
use PHPWorldWide\Stats\Collection\CommentCollection;
use PHPWorldWide\Stats\Collection\ReplyCollection;
use Symfony\Component\Console\Helper\ProgressBar;
use Facebook\Facebook;
use Facebook\Exceptions\FacebookSDKException;
use Facebook\Exceptions\FacebookResponseException;

/**
 * Class Mapper.
 */
class Mapper
{
    /**
     * @var Facebook
     */
    protected $fb;

    /**
     * @var Config
     */
    protected $config;

    /**
     * @var ProgressBar
     */
    protected $progress;

    /**
     * @var array
     */
    protected $feed = [];

    /**
     * @var Log
     */
    protected $log;

    /**
     * Mapper constructor.
     *
     * @param Config $config
     * @param ProgressBar $progress
     * @param Facebook $fb
     * @param Log $log
     */
    public function __construct(Config $config, ProgressBar $progress, Facebook $fb, Log $log)
    {
        $this->config = $config;
        $this->progress = $progress;
        $this->fb = $fb;
        $this->log = $log;
    }

    /**
     * Fetch feed from API data.
     *
     * @return array
     *
     * @throws \Exception
     */
    private function fetchFeed()
    {
        $this->progress->setMessage('Fetching feed...');
        $this->progress->advance();

        $this->feed = [];

        try {
            $pagesCount = 0;
            $startDate = \DateTime::createFromFormat('Y-m-d H:i:s', $this->config->get('start_datetime'));
            $response = $this->fb->get('/'.$this->config->get('group_id').'/feed?fields=comments.limit(200).summary(1){like_count,comment_count,from,created_time,message,can_comment,comments.limit(200).summary(1){like_count,comment_count,from,created_time,message}},likes.limit(0).summary(1),from,created_time,updated_time,message&include_hidden=true&limit=100&since='.$startDate->getTimestamp());

            $feedEdge = $response->getGraphEdge();

            do {
                ++$pagesCount;
                $this->progress->setMessage('Fetching feed from API page '.$pagesCount.' and with the topic updated '.$feedEdge[0]->getField('updated_time')->format('Y-m-d H:i:s'));
                $this->progress->advance();

                foreach ($feedEdge as $topic) {
                    $topicArray = $topic->asArray();
                    $topicArray['commentsCount'] = $topic->getField('comments')->getMetaData()['summary']['total_count'];
                    $topicArray['likesCount'] = $topic->getField('likes')->getMetaData()['summary']['total_count'];
                    $topicArray['canComment'] = $topic->getField('comments')->getMetaData()['summary']['can_comment'];
                    $this->feed[] = $topicArray;
                }
            } while ($feedEdge = $this->fb->next($feedEdge));
        } catch (FacebookResponseException $e) {
            // When Graph returns an error
            throw new \Exception('Graph returned an error: '.$e->getMessage());
        } catch (FacebookSDKException $e) {
            // When validation fails or other local issues
            throw new \Exception('Facebook SDK returned an error: '.$e->getMessage());
        }

        $this->progress->setMessage('Adding topics to collection...');
        $this->progress->advance();

        return $this->feed;
    }

    /**
     * Map topics from fetched feed array to topics collection.
     *
     * @param TopicCollection $topics
     * @param $startDate
     * @param $endDate
     *
     * @throws \Exception
     */
    public function mapTopics(TopicCollection $topics, $startDate, $endDate)
    {
        $topics->setStartDate($startDate);
        $topics->setEndDate($endDate);
        $topics->addTopicsFromFeed($this->fetchFeed());

        // log topics
        foreach ($topics as $id => $topic) {
            $log = $id."\t";
            $log .= ' Likes: '.$topic->getLikesCount()."\t";
            $log .= ' Comments: '.$topic->getCommentsCount()."\n";
            $this->log->logTopic($log);
        }
    }

    /**
     * Map comments from fetched feed to comments collection.
     *
     * @param CommentCollection $comments
     * @param $startDate
     * @param $endDate
     */
    public function mapComments(CommentCollection $comments, $startDate, $endDate)
    {
        $comments->setStartDate($startDate);
        $comments->setEndDate($endDate);
        $comments->addCommentsFromFeed($this->feed);
    }

    /**
     * Map replies from fetched feed to replies collection.
     *
     * @param ReplyCollection $replies
     * @param $startDate
     * @param $endDate
     */
    public function mapReplies(ReplyCollection $replies, $startDate, $endDate)
    {
        $replies->setStartDate($startDate);
        $replies->setEndDate($endDate);
        $replies->addRepliesFromFeed($this->feed);
    }

    /**
     * Map users from fetched feed to users collection.
     *
     * @param UserCollection $users
     * @param $startDate
     * @param $endDate
     */
    public function mapUsers(UserCollection $users, $startDate, $endDate)
    {
        $users->setStartDate($startDate);
        $users->setEndDate($endDate);
        $users->addUsersFromFeed($this->feed);

        // log all contributors
        foreach ($users->getTopUsers() as $id => $user) {
            $log = $id."\t";
            $log .= $user->getName()."\t";
            $log .= 'Points: '.$user->getPointsCount()."\t";
            $log .= 'Topics: '.$user->getTopicsCount()."\t";
            $log .= 'Comments: '.$user->getCommentsCount();
            $this->log->logContributor($log);
        }
    }

    /**
     * Get number of new users since the user's name set in app configuration.
     *
     * @return int
     *
     * @throws \Exception
     */
    public function getNewUsersCount()
    {
        $this->progress->setMessage('Retrieving members...');
        $this->progress->advance();
        $newUsersCount = 0;
        $pagesCount = 0;

        try {
            $response = $this->fb->get('/'.$this->config->get('group_id').'/members?fields=id,name&limit=1000');

            $feedEdge = $response->getGraphEdge();
            do {
                ++$pagesCount;
                $this->progress->setMessage('Retrieving members from API page '.$pagesCount);
                $this->progress->advance();

                foreach ($feedEdge as $status) {
                    // log new users
                    $log = $status->asArray()['id']."\t";
                    $log .= $status->asArray()['name']."\n";
                    $this->log->logNewUser($log);

                    if ($status->asArray()['name'] == $this->config->get('last_member_name')) {
                        break 2;
                    }
                    ++$newUsersCount;
                }

                if ($pagesCount == $this->config->get('api_pages')) {
                    break;
                }
            } while ($feedEdge = $this->fb->next($feedEdge));
        } catch (FacebookResponseException $e) {
            // When Graph returns an error
            throw new \Exception('Graph returned an error: '.$e->getMessage());
        } catch (FacebookSDKException $e) {
            // When validation fails or other local issues
            throw new \Exception('Facebook SDK returned an error: '.$e->getMessage());
        }

        $this->progress->advance();

        return $newUsersCount;
    }
}
