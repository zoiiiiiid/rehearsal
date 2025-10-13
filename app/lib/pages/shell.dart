import 'package:flutter/material.dart';
import '../theme.dart';
import 'home_page.dart';
import 'search_page.dart';
import 'create_page.dart';
import 'workshop_page.dart';
import 'profile_page.dart';

class Shell extends StatefulWidget {
  const Shell({super.key});
  @override
  State<Shell> createState() => _ShellState();
}

class _ShellState extends State<Shell> {
  int _index = 0;

  // ---------------------------- Key so we can call HomePage.refreshFromTab() when Home is tapped again.
  final GlobalKey _homeKey = GlobalKey();

  //------------------------------- Keep pages alive (state preserved) with IndexedStack.
  late final List<Widget> _pages = <Widget>[
    HomePage(key: _homeKey),
    const SearchPage(),
    const CreatePage(),
    const WorkshopPage(),
    const ProfilePage(),
  ];

  @override
  Widget build(BuildContext context) {
    return Theme(
      data: buildAppTheme(),
      child: Scaffold(
        body: IndexedStack(index: _index, children: _pages),
        bottomNavigationBar: NavigationBar(
          selectedIndex: _index,
          onDestinationSelected: (i) {
            if (i == _index && i == 0) {
              // Re-tap on Home -> trigger scroll-to-top + refresh
              final st = _homeKey.currentState;
              (st as dynamic)?.refreshFromTab?.call();
            } else {
              setState(() => _index = i);
            }
          },
          destinations: const [
            NavigationDestination(
              icon: Icon(Icons.home_outlined),
              selectedIcon: Icon(Icons.home_rounded, color: Colors.white),
              label: 'Home',
            ),
            NavigationDestination(
              icon: Icon(Icons.search_outlined),
              selectedIcon: Icon(Icons.search_rounded, color: Colors.white),
              label: 'Search',
            ),
            NavigationDestination(
              icon: Icon(Icons.add_box_outlined),
              selectedIcon: Icon(Icons.add_box_rounded, color: Colors.white),
              label: 'Create',
            ),
            NavigationDestination(
              icon: Icon(Icons.school_outlined),
              selectedIcon: Icon(Icons.school_rounded, color: Colors.white),
              label: 'Workshop',
            ),
            NavigationDestination(
              icon: Icon(Icons.person_outline),
              selectedIcon: Icon(Icons.person_rounded, color: Colors.white),
              label: 'Profile',
            ),
          ],
        ),
      ),
    );
  }
}
