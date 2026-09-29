<?php

namespace App\Enums;

/**
 * A ready-made starting point on the new-project page: a common business app, described in plain
 * language, that fills the prompt so the user can adjust it before sending.
 */
enum AppTemplate: string
{
    case Crm = 'crm';
    case ProjectTracker = 'project-tracker';
    case ContentCalendar = 'content-calendar';
    case Inventory = 'inventory';
    case Hiring = 'hiring';
    case Events = 'events';
    case HelpDesk = 'help-desk';
    case TimeOff = 'time-off';
    case Expenses = 'expenses';

    /**
     * Human-readable name, also used as the new project's name.
     */
    public function label(): string
    {
        return match ($this) {
            self::Crm => 'Sales CRM',
            self::ProjectTracker => 'Project Tracker',
            self::ContentCalendar => 'Content Calendar',
            self::Inventory => 'Inventory',
            self::Hiring => 'Hiring Pipeline',
            self::Events => 'Event Planner',
            self::HelpDesk => 'Help Desk',
            self::TimeOff => 'Time-Off Tracker',
            self::Expenses => 'Expense Approvals',
        };
    }

    /**
     * One line shown on the template's card.
     */
    public function description(): string
    {
        return match ($this) {
            self::Crm => 'Companies, contacts and a deal pipeline',
            self::ProjectTracker => 'Projects and tasks on a board, list and calendar',
            self::ContentCalendar => 'Plan posts by channel, status and date',
            self::Inventory => 'Stock levels, locations and low-stock alerts',
            self::Hiring => 'Candidates moving through interview stages',
            self::Events => 'Events, guest lists and RSVPs',
            self::HelpDesk => 'Support requests with owners and priorities',
            self::TimeOff => 'Requests, approvals and a team calendar',
            self::Expenses => 'Submit receipts and approve reimbursements',
        };
    }

    /**
     * The description sent to the agent: the records, views and people the app is for.
     */
    public function prompt(): string
    {
        return match ($this) {
            self::Crm => 'A sales CRM for our team. Track companies, the contacts at each company, and deals (name, value, stage, close date, owner). '
                .'Show deals on a pipeline board I can drag between stages (Lead, Qualified, Proposal, Won, Lost), plus searchable, sortable tables for companies and contacts. '
                .'Let me log notes and calls on any record. Team members sign in; admins can see everything. Add some sample data so I can try it.',
            self::ProjectTracker => 'A project tracker for our team. Projects have tasks with a title, status (To do, In progress, Done), assignee, due date and priority. '
                .'Show tasks as a board I can drag between statuses, a filterable list, and a calendar by due date. '
                .'Let people comment on tasks and see what\'s assigned to them. Team members sign in. Add some sample data so I can try it.',
            self::ContentCalendar => 'A content calendar for our marketing team. Each post has a title, channel (Blog, Newsletter, Instagram, LinkedIn), status (Idea, Drafting, Scheduled, Published), owner, publish date and notes. '
                .'Show posts on a monthly calendar and as a table I can filter by channel and status. Team members sign in. Add some sample data so I can try it.',
            self::Inventory => 'An inventory tracker. Items have a name, SKU, category, location, quantity, reorder level and supplier, with a photo. '
                .'Show a searchable table of items, highlight anything below its reorder level, and keep a history of stock added and removed. '
                .'Team members sign in; admins manage suppliers and locations. Add some sample data so I can try it.',
            self::Hiring => 'A hiring pipeline. We have open roles, and candidates apply to a role through a public application form (name, email, résumé upload, a short note). '
                .'Show each role\'s candidates on a board I can drag between stages (Applied, Screen, Interview, Offer, Hired, Rejected), and let interviewers leave ratings and notes. '
                .'The team signs in to see candidates. Add some sample data so I can try it.',
            self::Events => 'An event planner. Events have a name, date, location, capacity and description. '
                .'Each event gets a public RSVP page where guests enter their name and email and say whether they\'re coming. '
                .'Show each event\'s guest list with a headcount, and a calendar of upcoming events. Organizers sign in. Add some sample data so I can try it.',
            self::HelpDesk => 'A help desk for support requests. Anyone can submit a request through a public form (name, email, subject, details, attachments). '
                .'The team signs in to see requests in a table and on a board by status (New, Open, Waiting, Solved), assign an owner and priority, and reply with notes. '
                .'Show counts of open requests by priority. Add some sample data so I can try it.',
            self::TimeOff => 'A time-off tracker for my team. People sign in and request time off (type: vacation, sick, personal; start and end date; note). '
                .'Managers approve or decline requests. Show everyone\'s approved time off on a team calendar, and how many days each person has left this year. '
                .'Add some sample data so I can try it.',
            self::Expenses => 'An expense report app with approvals. People sign in and submit expenses (date, amount, category, description, receipt photo). '
                .'Managers approve or reject them with a comment, and finance marks approved expenses as paid. '
                .'Show a table of expenses I can filter by person, status and month, with totals. Add some sample data so I can try it.',
        };
    }

    /**
     * Every template, as the new-project page shows them.
     *
     * @return list<array{value: string, label: string, description: string, prompt: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $template) => [
            'value' => $template->value,
            'label' => $template->label(),
            'description' => $template->description(),
            'prompt' => $template->prompt(),
        ], self::cases());
    }
}
