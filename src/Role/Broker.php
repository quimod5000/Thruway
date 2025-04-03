<?php

namespace Thruway\Role;

use DateTime;
use Thruway\AbstractSession;
use Thruway\Common\Utils;
use Thruway\Event\LeaveRealmEvent;
use Thruway\Event\MessageEvent;
use Thruway\Logging\Logger;
use Thruway\Message\ErrorMessage;
use Thruway\Message\Message;
use Thruway\Message\PublishedMessage;
use Thruway\Message\PublishMessage;
use Thruway\Message\SubscribeMessage;
use Thruway\Message\UnsubscribeMessage;
use Thruway\Message\WelcomeMessage;
use Thruway\Module\RealmModuleInterface;
use Thruway\Session;
use Thruway\Subscription\ExactMatcher;
use Thruway\Subscription\MatcherInterface;
use Thruway\Subscription\PrefixMatcher;
use Thruway\Subscription\StateHandlerRegistry;
use Thruway\Subscription\Subscription;
use Thruway\Subscription\SubscriptionGroup;

/**
 * Class Broker
 * @package Thruway\Role
 */
class Broker implements RealmModuleInterface
{
    /**
     * @var array
     */
    protected $subscriptionGroups = [];

    /**
     * @var array
     */
    protected $matchers = [];

    /**
     * @var StateHandlerRegistry
     */
    protected $stateHandlerRegistry;

    /**
     *
     */
    public function __construct()
    {
        $this->addMatcher(new ExactMatcher());
        $this->addMatcher(new PrefixMatcher());
    }

    /**
     *
     * @return array
     */
    public function getSubscribedRealmEvents()
    {
        return [
            'PublishMessageEvent'     => ['handlePublishMessage', 10],
            'SubscribeMessageEvent'   => ['handleSubscribeMessage', 10],
            'UnsubscribeMessageEvent' => ['handleUnsubscribeMessage', 10],
            'LeaveRealm'              => ['handleLeaveRealm', 10],
            'SendWelcomeMessageEvent' => ['handleSendWelcomeMessage', 20]
        ];
    }

    /**
     * @param \Thruway\Event\MessageEvent $event
     */
    public function handlePublishMessage(MessageEvent $event)
    {
        $this->processPublish($event->session, $event->message);
    }

    /**
     * @param \Thruway\Event\MessageEvent $event
     */
    public function handleSubscribeMessage(MessageEvent $event)
    {
        $this->processSubscribe($event->session, $event->message);
    }

    /**
     * @param \Thruway\Event\MessageEvent $event
     */
    public function handleUnsubscribeMessage(MessageEvent $event)
    {
        $this->processUnsubscribe($event->session, $event->message);
    }

    /**
     * @param \Thruway\Event\LeaveRealmEvent $event
     */
    public function handleLeaveRealm(LeaveRealmEvent $event)
    {
        $this->leave($event->session);
    }

    /**
     * @param \Thruway\Event\MessageEvent $event
     */
    public function handleSendWelcomeMessage(MessageEvent $event)
    {
        /** @var WelcomeMessage $welcomeMessage */
        $welcomeMessage = $event->message;

        //Tell the welcome message what features we support
        $welcomeMessage->addFeatures('broker', $this->getFeatures());
    }

    /**
     * Return supported features
     *
     * @return \stdClass
     */
    public function getFeatures()
    {
        $features = new \stdClass();

        $features->subscriber_blackwhite_listing = true;
        $features->publisher_exclusion           = true;
        $features->subscriber_metaevents         = true;

        return $features;
    }

    /**
     * Process subscribe message
     *
     * @param \Thruway\Session $session
     * @param \Thruway\Message\SubscribeMessage $msg
     * @throws \Exception
     */
    protected function processSubscribe(Session $session, SubscribeMessage $msg)
    {
        // get a subscription group "hash"
        /** @var MatcherInterface $matcher */
        $matcher = $this->getMatcherForMatchType($msg->getMatchType());
        if ($matcher === false) {
            Logger::alert(
                $this,
                "no matching match type for \"" . $msg->getMatchType() . "\" for URI \"" . $msg->getUri() . "\""
            );

            return;
        }

        if (!$matcher->uriIsValid($msg->getUri(), $msg->getOptions())) {
            $errorMsg = ErrorMessage::createErrorMessageFromMessage($msg);
            $session->sendMessage($errorMsg->setErrorURI('wamp.error.invalid_uri'));

            return;
        }

        $matchHash = $matcher->getMatchHash($msg->getUri(), $msg->getOptions());

        $isNewSubscriptionGroup = false;
        if (!isset($this->subscriptionGroups[$matchHash])) {
            $this->subscriptionGroups[$matchHash] = new SubscriptionGroup($matcher, $msg->getUri(), $msg->getOptions());
            $isNewSubscriptionGroup = true;
        }

        /** @var SubscriptionGroup $subscriptionGroup */
        $subscriptionGroup = $this->subscriptionGroups[$matchHash];
        $subscription      = $subscriptionGroup->processSubscribe($session, $msg);

        $data = $session->getMetaInfo();
        $data['created'] = (new DateTime())->format('c');
        $data['uri'] = $subscriptionGroup->getUri();
        $data['match'] = $subscriptionGroup->getMatchType();


        //Fire off subscription.on_create meta event
        $isMetaSubscription = is_string($data['uri']) && str_starts_with($data['uri'], 'wamp.metaevent');
        if ($isNewSubscriptionGroup && !$isMetaSubscription) {
            $session->getRealm()->publishMeta('wamp.metaevent.subscription.on_create', [$data]);
        }
        //Fire off subscription.on_subscribe meta event (all previous values on data will be useful) 
        if (!$isMetaSubscription) {
            $data['subscription'] = $subscription->getId();
            $session->getRealm()->publishMeta('wamp.metaevent.subscription.on_subscribe', [$data]);
        }

        $registry = $this->getStateHandlerRegistry();
        if ($registry !== null) {
            $registry->processSubscriptionAdded($subscription);
        }
    }

    /**
     * Process publish message
     *
     * @param \Thruway\Session $session
     * @param \Thruway\Message\PublishMessage $msg
     */
    protected function processPublish(Session $session, PublishMessage $msg)
    {
        if ($msg->getPublicationId() === null) {
            $msg->setPublicationId(Utils::getUniqueId());
        }

        /** @var SubscriptionGroup $subscriptionGroup */
        foreach ($this->subscriptionGroups as $subscriptionGroup) {
            $subscriptionGroup->processPublish($session, $msg);
        }

        if ($msg->acknowledge()) {
            $session->sendMessage(new PublishedMessage($msg->getRequestId(), $msg->getPublicationId()));
        }
    }

    /**
     * Process Unsubscribe message
     *
     * @param \Thruway\Session $session
     * @param \Thruway\Message\UnsubscribeMessage $msg
     */
    protected function processUnsubscribe(Session $session, UnsubscribeMessage $msg)
    {
        $subId = $msg->getSubscriptionId();
        $group = $this->GetGroupOfSubscription($subId);
        if (!$group) return; //Sub should have existed in exactly 1 group
        $result = $group->processUnsubscribeBySubscriptionId($subId, $session->getSessionId(), false);
        $subscription = $result->sub;
        if (!$subscription) {
            if ($result->error) {
                Logger::alert($this, $result->error . json_encode($msg));
            }
            $errorMsg = ErrorMessage::createErrorMessageFromMessage($msg);
            $session->sendMessage($errorMsg->setErrorURI('wamp.error.no_such_subscription'));
            return;
        }

        $group->sendClientAcknowledgementUnsubscribed($session, $msg);        
        $this->FireOffMetaEventsForSubscription($subscription, $session);
    }


    public function adminUnsubscribeBySubscriptionId(int $subscriptionId) 
    {
        $group = $this->GetGroupOfSubscription($subscriptionId);
        if (!$group) return; //Sub should have existed in exactly 1 group
        $result = $group->processUnsubscribeBySubscriptionId($subscriptionId, 0, true);
        $subscription = $result->sub;
        if (!$subscription) {
            if ($result->error) {
                Logger::alert($this, $result->error);
            }
            return; //Couldn't find this subscription
        }
        $this->FireOffMetaEventsForSubscription($subscription, $subscription->getSession());
    }   

    /**
     * @param Subscription $subscription  
     * @param Session $session to use for meta info 
     *     (pass in the client session that requested it, or the subscription's session if it's an admin kick)   
     * @return void
     */
    public function FireOffMetaEventsForSubscription(Subscription $subscription, Session $session)
    {

        $isLastSubscriptionInGroup = $subscription->getSubscriptionGroup()->getSubscriptionCount() <= 0;
        $data = $session->getMetaInfo();
        $data['uri'] = $subscription->getUri();
        $data['match'] = $subscription->getSubscriptionGroup()->getMatchType();
        $data['subscription'] = $subscription->getId();

        //Fire off the subscription.on_unsubscribe, unless it's a subscription TO a meta event
        $isMetaSubscription = is_string($data['uri']) && str_starts_with($data['uri'], 'wamp.metaevent');
        if (!$isMetaSubscription) {
            $session->getRealm()->publishMeta('wamp.metaevent.subscription.on_unsubscribe', [$data]);
        }

        //If this was the last subscription for the topic, notify the delete        
        if ($isLastSubscriptionInGroup && !$isMetaSubscription) {
            $session->getRealm()->publishMeta('wamp.metaevent.subscription.on_delete', [$data]);
        }
    }

    /**
     * @param int $subscriptionId     
     * @return SubscriptionGroup|null
     */
    private function GetGroupOfSubscription($subscriptionId)
    {
        foreach ($this->subscriptionGroups as $subscriptionGroup) {
            if ($subscriptionGroup->containsSubscriptionId($subscriptionId))
                return $subscriptionGroup;
        }
        return null;
    }

    /**
     * @param MatcherInterface $matcher
     * @return bool
     */
    public function addMatcher(MatcherInterface $matcher)
    {
        foreach ($matcher->getMatchTypes() as $matchType) {
            if (isset($this->matchers[$matchType])) {
                return false;
            }
        }

        foreach ($matcher->getMatchTypes() as $matchType) {
            $this->matchers[$matchType] = $matcher;
        }

        return true;
    }

    /**
     * @param $matchType
     * @return MatcherInterface|bool
     */
    public function getMatcherForMatchType($matchType)
    {
        if (isset($this->matchers[$matchType])) {
            return $this->matchers[$matchType];
        }

        return false;
    }

    /**
     * @param Session $session
     */
    public function leave(Session $session)
    {
        /** @var SubscriptionGroup $subscriptionGroup */
        foreach ($this->subscriptionGroups as $key => $subscriptionGroup) {
            /** @var Subscription $subscription */
            foreach ($subscriptionGroup->getSubscriptions() as $subscription) {
                if ($subscription->getSession() === $session) {
                    $subscriptionGroup->removeSubscription($subscription);
                }

                $subscriptions = $subscriptionGroup->getSubscriptions();
                if (empty($subscriptions)) {
                    unset($this->subscriptionGroups[$key]);
                }
            }
        }
    }

    /**
     * todo: this may be used by testing
     *
     * @return array
     */
    public function managerGetSubscriptions()
    {
        return [$this->getSubscriptions()];
    }

    /**
     * @return array
     */
    public function getSubscriptions()
    {
        // collect all the subscriptions into an array
        $subscriptions = [];
        /** @var SubscriptionGroup $subscriptionGroup */
        foreach ($this->subscriptionGroups as $subscriptionGroup) {
            $subscriptions = array_merge($subscriptions, $subscriptionGroup->getSubscriptions());
        }

        return $subscriptions;
    }

    /**
     * @param $id
     * @return bool
     */
    public function getSubscriptionById($id)
    {
        /** @var SubscriptionGroup $subscriptionGroup */
        foreach ($this->subscriptionGroups as $subscriptionGroup) {
            if ($subscriptionGroup->containsSubscriptionId($id)) {
                return $subscriptionGroup->getSubscriptions()[$id];
            }
        }

        return false;
    }

    /**
     * @return StateHandlerRegistry
     */
    public function getStateHandlerRegistry()
    {
        return $this->stateHandlerRegistry;
    }

    /**
     * @param StateHandlerRegistry $stateHandlerRegistry
     */
    public function setStateHandlerRegistry($stateHandlerRegistry)
    {
        $this->stateHandlerRegistry = $stateHandlerRegistry;
    }

    /**
     * @return array
     */
    public function getSubscriptionGroups()
    {
        return $this->subscriptionGroups;
    }

    public function getSubscriptionGroupForTopic($topicName)
    {
        $matchHash = "exact_" . $topicName;
        $grp = $this->subscriptionGroups[$matchHash];
        return $grp;
    }
}
