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

In the event, *Travel by bus*: switch on **A bus is booked for this event** and enter the **seats** (without the driver).

- **Everybody travels by bus** unless set otherwise: everybody who takes part (registered, confirmed) needs a seat. People on the
  waitlist and cancelled registrations do not count.
- The participants of the event and the dashboard show how full the bus is, e.g. *Bus: 48 of 50 seats taken, 2 free. By car: 6.* When
  more people are on the bus than it has seats, the line turns red (**overbooked**) and names how many are too many and how many adults
  are still on the bus. Administrators, the board and the camp leaders of the event see it.
- **Adults** – when the bus is too small, set adults to **Travels by car**: in the menu of a row, or tick several rows and choose
  *Travel by car* under *Bulk actions*. The number on the bus goes down at once.
- **Children** – a child normally takes the bus. If the legal guardians bring it themselves, e.g. because the camp is on the way to their
  holidays, set the child to *Travels by car* in the same way. The column *Travel* then shows *Car (brought)*, and the seat is free.
- *Travels by bus* puts somebody back on the bus.
- **Lists** (*Events → Lists*): the *Bus list* holds exactly the people on the bus, with boxes to tick for the outward and return
  journey. *Travel by car* lists everybody else: adults with their phone number, children with the legal guardian who brings them and
  that person's phone number – so the team knows at the meeting point who is not missing, and at the camp who still arrives.
- The API returns and accepts `travel` (`bus` or `car`) on a participation.

If no bus is booked, nothing of this is shown.

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
