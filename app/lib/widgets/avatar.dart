import 'package:flutter/material.dart';

class Avatar extends StatelessWidget {
  final String? url;
  final double size;
  final double border; // ring width
  final Color borderColor;
  final IconData placeholder;

  const Avatar({
    super.key,
    this.url,
    this.size = 34,
    this.border = 1.2,
    this.borderColor = Colors.black,
    this.placeholder = Icons.person,
  });

  @override
  Widget build(BuildContext context) {
    final u = (url ?? '').trim();
    final image = u.isNotEmpty ? NetworkImage(u) : null;

    return Container(
      padding: EdgeInsets.all(border),
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        border: Border.all(color: borderColor, width: border),
      ),
      child: CircleAvatar(
        radius: size / 2,
        backgroundImage: image,
        child: image == null ? Icon(placeholder, size: size * .55) : null,
      ),
    );
  }
}
