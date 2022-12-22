<?php

namespace SilverStripe\Intercom\Task;

use SilverStripe\Dev\BuildTask;
use Guzzle\Service\Resource\Model;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Intercom\Intercom;
use SilverStripe\Intercom\Model\Conversation;
use SilverStripe\Intercom\Model\Lead;
use SilverStripe\Control\HTTPRequest;
use stdClass;

class ConnectLeadsAndConversations extends BuildTask
{
    /**
     * @var array
     *
     * @config
     */
    private static $connectors = [];

    /**
     * @var string
     *
     * @config
     */
    private static $assignment_admin_id;

    /**
     * @inheritdoc
     *
     * @param SS_HTTPRequest $request
     */
    public function run($request)
    {
        $client = Injector::inst()->get(Intercom::class);

        $connectors = $this->getConnectors();

        /** @var Model $response */
        $response = $client->getClient()->leads->getLeads([
            "created_since" => 1,
        ]);

        $contacts = $response->contacts;

        foreach ($connectors as $connector) {
            foreach ($contacts as $i => $contact) {
                $existingLead = Lead::get()
                    ->filter("IntercomID", $contact->id)
                    ->first();

                if (!$existingLead) {
                    $existingLead = Lead::create();
                    $existingLead->IntercomID = $contact->id;
                    $existingLead->IsAssigned = false;
                    $existingLead->write();
                }

                if ($existingLead->IsAssigned) {
                    continue;
                }

                /** @var Model $response */
                $response = $client->getClient()->notes->getNotes([
                    "id" => $contact->id,
                ]);

                $notes = $response->notes;

                $contactArray = json_decode(json_encode($contact), true);

                if (!$connector->shouldConnect($contactArray, $notes)) {
                    continue;
                }

                $existingConversation = Conversation::get()
                    ->filter("LeadID", $contact->id)
                    ->first();

                if (!$existingConversation) {
                    $client->getClient()->messages->create([
                        "body" => $connector->getMessage($contactArray, $notes),
                        "from" => [
                            "type" => "contact",
                            "id" => $contact->id
                        ]
                    ]);

                    /** @var Model $response */
                    $response = $client->getClient()->conversations->getConversations([
                        "intercom_user_id" => $contact->id,
                    ]);

                    $conversations = $response->conversations;

                    $existingConversation = Conversation::create();
                    $existingConversation->LeadID = $existingLead->ID;
                    $existingConversation->IntercomID = $conversations[0]["id"];
                    $existingConversation->write();
                }

                $client->getClient()->conversations->replyToConversation([
                    "id" => $existingConversation->IntercomID,
                    "type" => "admin",
                    "message_type" => "note",
                    "body" => join("\n\n", array_map(function ($note) {
                        return $note->body;
                    }, $notes)),
                    "admin_id" => $this->config()->assignment_admin_id,
                ]);

                $teamIdentifier = $connector->getTeamIdentifier($contactArray, $notes);

                if ($teamIdentifier) {
                    $client->getClient()->conversations->replyToConversation([
                        "id" => $existingConversation->IntercomID,
                        "type" => "admin",
                        "message_type" => "assignment",
                        "admin_id" => $this->config()->assignment_admin_id,
                        "assignee_id" => $teamIdentifier,
                    ]);
                }

                $tags = $connector->getTags($contactArray, $notes);

                if (!empty($tags)) {
                    foreach ($tags as $tag) {
                        $client->getClient()->tags->tag([
                            "name" => $tag,
                            "users" => [
                                ["id" => $contact->id],
                            ],
                        ]);
                    }
                }

                $existingLead->IsAssigned = true;
                $existingLead->write();
            }
        }
    }

    /**
     * @return LeadAndConversationConnector[]
     */
    private function getConnectors()
    {
        /** @var StdClass $config */
        $config = $this->config();

        $classes = (array)$config->connectors;

        $mapped = array_map(function ($class) {
            return Injector::inst()->create($class);
        }, $classes);

        $filtered = array_filter($mapped, function ($connector) {
            return $connector instanceof LeadAndConversationConnector;
        });

        return $filtered;
    }
}
