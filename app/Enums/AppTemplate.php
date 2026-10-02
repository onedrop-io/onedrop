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
    case ProductRoadmap = 'product-roadmap';
    case BugTracker = 'bug-tracker';
    case Okrs = 'okrs';
    case Feedback = 'feedback';
    case Directory = 'directory';
    case Onboarding = 'onboarding';
    case Assets = 'assets';
    case Shifts = 'shifts';
    case ClientPortal = 'client-portal';
    case Grants = 'grants';
    case Volunteers = 'volunteers';
    case Rentals = 'rentals';

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
            self::ProductRoadmap => 'Product Roadmap',
            self::BugTracker => 'Bug Tracker',
            self::Okrs => 'Goals & OKRs',
            self::Feedback => 'Customer Feedback',
            self::Directory => 'Team Directory',
            self::Onboarding => 'Employee Onboarding',
            self::Assets => 'Equipment Checkout',
            self::Shifts => 'Shift Schedule',
            self::ClientPortal => 'Client Portal',
            self::Grants => 'Grant Tracker',
            self::Volunteers => 'Volunteer Scheduling',
            self::Rentals => 'Rental Properties',
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
            self::ProductRoadmap => 'Features by quarter, linked to the goals they serve',
            self::BugTracker => 'Bug reports with severity, steps and owners',
            self::Okrs => 'Quarterly objectives, key results and check-ins',
            self::Feedback => 'Collect feedback, tag themes and spot trends',
            self::Directory => 'People, teams and an org chart',
            self::Onboarding => 'Checklists that get new hires ready for day one',
            self::Assets => 'Laptops and gear, who has them and when they\'re due back',
            self::Shifts => 'Weekly shifts, availability and swaps',
            self::ClientPortal => 'Share project updates, files and invoices with clients',
            self::Grants => 'Funders, applications, deadlines and reports',
            self::Volunteers => 'Volunteers sign up for shifts by skill',
            self::Rentals => 'Units, tenants, leases and rent due',
        };
    }

    /**
     * What every template asks of its lists, so the agent builds them on the table kit (TABLE-001).
     */
    public const SPREADSHEET = 'Lists of records work like a spreadsheet: edit cells in place, paste from Excel, add our own fields and formulas, and save filtered views.';

    /**
     * The description sent to the agent: the records, views and people the app is for.
     */
    public function prompt(): string
    {
        return str_replace(' Add some sample data so I can try it.', ' '.self::SPREADSHEET.' Add some sample data so I can try it.', $this->brief());
    }

    /**
     * The template's own description, before what every template asks of its lists.
     */
    protected function brief(): string
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
            self::ProductRoadmap => 'A product roadmap for our team. We have goals for the year, and features that each serve a goal, with a title, description, status (Idea, Planned, Building, Shipped), quarter, effort (S, M, L), owner and linked customer requests. '
                .'Show features on a timeline by quarter, on a board I can drag between statuses, and grouped under each goal with how much of it has shipped. '
                .'Team members sign in; anyone can vote on ideas. Add some sample data so I can try it.',
            self::BugTracker => 'A bug tracker for our software. Anyone can report a bug through a public form (title, what happened, steps to reproduce, browser or device, screenshot). '
                .'The team signs in to see bugs in a table and on a board by status (New, Confirmed, Fixing, Fixed, Won\'t fix), set severity (Critical, High, Medium, Low), assign an owner and link duplicates. '
                .'Show open bugs by severity, and who is working on what. Add some sample data so I can try it.',
            self::Okrs => 'A goals tracker for our company using OKRs. Each quarter, teams set objectives, and each objective has key results with a starting value, target, current value and owner. '
                .'Owners post a weekly check-in (new value, confidence: on track, at risk, off track, and a note). '
                .'Show each objective\'s progress from its key results, a company view grouped by team, and which key results are at risk. Team members sign in. Add some sample data so I can try it.',
            self::Feedback => 'A customer feedback tracker. Feedback comes in through a public form (name, email, product area, rating from 1 to 5, comments) and the team can add notes from calls and reviews too. '
                .'The team signs in to tag each piece of feedback with themes, mark it positive, neutral or negative, and link it to a feature request. '
                .'Show the most common themes, ratings over time, and feature requests ranked by how many customers asked. Add some sample data so I can try it.',
            self::Directory => 'A team directory for our company. Each person has a photo, name, job title, team, manager, location, email, phone, start date and a short bio. '
                .'Show a searchable card grid of everyone, a page per team, and an org chart built from who reports to whom. '
                .'Everyone signs in and can edit their own profile; admins manage teams. Add some sample data so I can try it.',
            self::Onboarding => 'An employee onboarding app. When we hire someone, HR adds them with a start date, role, team and manager, and they get a checklist from a template for their role, with tasks for the new hire, their manager, IT and HR, each due a number of days before or after the start date. '
                .'Show each new hire\'s progress, everyone\'s overdue tasks, and a list of upcoming start dates. '
                .'People sign in and see the tasks assigned to them. Add some sample data so I can try it.',
            self::Assets => 'An equipment tracker for our office. Each asset has a name, type (laptop, monitor, camera, phone…), serial number, purchase date, cost, condition and a photo. '
                .'People sign in to check equipment out and back in, with a due date; admins see who has what, what\'s overdue, and each asset\'s history. '
                .'Show a searchable table of assets with their status (Available, Checked out, In repair, Retired). Add some sample data so I can try it.',
            self::Shifts => 'A shift schedule for our team. Managers create shifts (date, start and end time, role, location) and assign people to them on a weekly calendar. '
                .'Staff sign in to see their upcoming shifts, set the days they can\'t work, and offer a shift for someone else to pick up, which a manager approves. '
                .'Show hours scheduled per person each week. Add some sample data so I can try it.',
            self::ClientPortal => 'A client portal for our agency. We have clients, and each client has projects with a status, milestones, files and invoices (number, amount, due date, paid or not). '
                .'Our team signs in to post updates and upload files; each client\'s people sign in to see only their own projects, download files, approve deliverables and comment. '
                .'Show the team an overview of every client\'s open projects and unpaid invoices. Add some sample data so I can try it.',
            self::Grants => 'A grant tracker for our nonprofit. We keep a list of funders (name, contact, focus areas, notes) and grant applications to them with an amount requested, amount awarded, status (Researching, Writing, Submitted, Awarded, Declined), deadline and owner. '
                .'Awarded grants have reports due on set dates. Show applications on a board by status, a calendar of deadlines and report dates, and the total awarded this year. '
                .'Team members sign in. Add some sample data so I can try it.',
            self::Volunteers => 'A volunteer scheduling app. Coordinators post shifts (event, date, time, location, number of people needed, skills needed). '
                .'Volunteers sign up through a public page with their name, email, phone and skills, and pick the shifts they can do. '
                .'Coordinators sign in to see who\'s coming to each shift, which shifts still need people, and each volunteer\'s total hours. Add some sample data so I can try it.',
            self::Rentals => 'A rental property manager. We have properties, each with units (bedrooms, rent, status: occupied or vacant), and tenants with leases (start and end date, rent, deposit). '
                .'Record rent payments and maintenance requests (unit, issue, priority, status). '
                .'Show who owes rent this month, leases ending in the next 90 days, and open maintenance requests. The landlord and staff sign in. Add some sample data so I can try it.',
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
