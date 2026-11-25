# Events, registration and waitlist

Questions when registering, public forms and guests: [Registration](registration.md). Lists: [Lists](lists.md).

Templates, dates, public pages and rounds: [Event planning](event-planning.md).

## Event series and events

Events of the same kind form a **series** (e.g. the camp series, the alumni-event series). An event has dates,
place, meeting point, a status (draft → announced → registration open → registration closed → running → finished
→ archived) and one or more **participant roles** (child, junior counsellor, team, guest). Per role:

- **Places** (empty = unlimited) and **reserve places**
- **Minimum and maximum age** – calculated at the start of the event, or at a different reference date
- **Who registers**: legal guardians, the person itself, or only the team

Registrations are **binding immediately** by default; switch this off to treat registrations as requests that the
team confirms.

## Travel by bus

In the event, *Travel by bus*: switch on **A bus is booked for this event** and enter the **seats**. Everybody who takes part (registered,
confirmed) counts as travelling by bus. The participants and the dashboard show how full the bus is – in red when it is **overbooked**, with
the number of adults who are on the bus. Set adults to **Travels by car** (per row, or tick several and choose *Travel by car*); the
bus count goes down at once. Children always travel by bus. The lists *Bus list* and *Travel by car* follow these settings; the API
returns and accepts `travel` (`bus`/`car`) on a participation.

## Waitlist

When the bookable places of a role are taken, further registrations go to the **waitlist**, ordered by
registration date. Legal guardians see the position and the total length in the portal ("position 3 of 9").
When someone cancels, everyone behind moves up.

- **Order**: per role, criteria one after the other – priority, took part last time, number of events so far. The registration date
  always decides in the end. The portal shows the rule, but never the reasons of others.
- **Priority** flags (sibling, social reasons, other) are set by the team; a sibling that takes part counts automatically (not if it
  registered itself). Flags and notes are internal.
- *Events → Waitlist* shows the order with its reason and open offers with their deadlines. The team can offer a place (out of turn
  only with a reason), assign a reserve place, set priority, or **skip** somebody with a reason: the position stays, but there are no
  offers until this is taken back.
- Free places are also offered after the registration is closed.

**Reserve places** are not given out by the system: the waitlist starts earlier (bookable places = places −
reserve places). Only the team assigns a reserve place to someone on the waitlist, with an internal reason
(e.g. special needs). The waitlist order of the others is not affected, and the portal does not show reserve places.

Free reserve places can be **released** to the waitlist there, with a reason. With *Reserve places: remind on*, all administrators get
a mail on that day if reserve places are still free; *Release automatically* is off by default.

## Portal for legal guardians

Legal guardians sign up at `/register` with name and e-mail address and receive a login link. In the portal they
add children, see the open events that match the child's age, register (or join the waitlist) and cancel.
